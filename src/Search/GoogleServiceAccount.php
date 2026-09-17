<?php

namespace Gadya\Cms\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Speaks to Google as a service account, without the SDK: a signed JWT
 * exchanged for a short-lived token. The key JSON is the one Google Cloud
 * hands out when a service account is created; the account then needs to
 * be added to the Search Console property as a user.
 */
class GoogleServiceAccount
{
    public function __construct(private readonly string $json) {}

    /**
     * @return array{client_email: string, private_key: string}
     */
    public function credentials(): array
    {
        $data = json_decode($this->json, true);

        if (! is_array($data) || ! isset($data['client_email'], $data['private_key'])) {
            throw new RuntimeException('That is not a Google service account key. Paste the whole JSON file Google Cloud gave you.');
        }

        return ['client_email' => (string) $data['client_email'], 'private_key' => (string) $data['private_key']];
    }

    public function email(): string
    {
        return $this->credentials()['client_email'];
    }

    /**
     * @param  list<string>  $scopes
     */
    public function token(array $scopes): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'gadya-cms.google-token.'.md5($credentials['client_email'].implode(' ', $scopes));

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials, $scopes): string {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($credentials, $scopes),
            ]);

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Google refused the service account: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * @param  array{client_email: string, private_key: string}  $credentials
     * @param  list<string>  $scopes
     */
    private function assertion(array $credentials, array $scopes): string
    {
        $encode = fn (array|string $data): string => rtrim(strtr(base64_encode(is_array($data) ? (string) json_encode($data) : $data), '+/', '-_'), '=');

        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $encode([
            'iss' => $credentials['client_email'],
            'scope' => implode(' ', $scopes),
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => time(),
            'exp' => time() + 3600,
        ]);

        $signature = '';

        if (! openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account key could not be used to sign a request; is the private key intact?');
        }

        return "{$header}.{$claims}.".$encode($signature);
    }
}
