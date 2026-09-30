<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\ClientIp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The quiet ways a built form tells a person from a bot, on top of the
 * honeypot and the rate limit every form already has.
 *
 * - **The time trap.** Each form carries the moment it was drawn, sealed
 *   with the application key. A person takes more than a few seconds to
 *   fill anything in; a script posting the moment it loads does not. A
 *   form posted without the seal - hand-written HTML from before the form
 *   was built, say - is let through, so converting a form never breaks
 *   the page it is on.
 * - **Cloudflare Turnstile**, when the client has put the keys in under
 *   Settings → Spam protection and switched it on for the form.
 */
class SpamGuard
{
    public const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public function __construct(private readonly Options $options) {}

    public function seal(string $slug): string
    {
        return Crypt::encryptString(json_encode(['f' => $slug, 't' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    /** When the sealed form was drawn, or null when there is no good seal. */
    public function drawnAt(?string $token, string $slug): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $sealed = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|Throwable) {
            return null;
        }

        return is_array($sealed) && ($sealed['f'] ?? null) === $slug && is_int($sealed['t'] ?? null) ? $sealed['t'] : null;
    }

    /**
     * Whether this looks like a bot: filled in too fast, or carrying a
     * seal that was tampered with or made for another form.
     */
    public function isTooFast(Request $request, string $slug): bool
    {
        $token = $request->input('_t');

        if (! is_string($token) || $token === '') {
            return false;
        }

        $drawnAt = $this->drawnAt($token, $slug);

        if ($drawnAt === null) {
            return true;
        }

        return now()->getTimestamp() - $drawnAt < (int) config('gadya-cms.forms.builder.min_seconds', 3);
    }

    public function turnstileSiteKey(): ?string
    {
        $key = $this->options->get('forms.turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function turnstileConfigured(): bool
    {
        return $this->turnstileSiteKey() !== null && $this->options->getSecret('forms.turnstile.secret') !== null;
    }

    /**
     * Ask Cloudflare whether the widget was passed. A Cloudflare that
     * cannot be reached lets the visitor through: an enquiry lost to an
     * outage costs the client more than one bit of spam.
     */
    public function passesTurnstile(Request $request): bool
    {
        $secret = $this->options->getSecret('forms.turnstile.secret');
        $response = $request->input('cf-turnstile-response');

        if ($secret === null) {
            return true;
        }

        if (! is_string($response) || $response === '') {
            return false;
        }

        try {
            $answer = Http::asForm()->timeout(8)->post(self::TURNSTILE_VERIFY, [
                'secret' => $secret,
                'response' => $response,
                'remoteip' => ClientIp::for($request),
            ]);
        } catch (Throwable) {
            return true;
        }

        if ($answer->serverError()) {
            return true;
        }

        return (bool) $answer->json('success', false);
    }
}
