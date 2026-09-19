<?php

namespace Gadya\Cms\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Asks public DNS-over-HTTPS resolvers what the world sees for a name,
 * rather than the server's own resolver: it has a timeout, it answers every
 * record type the same way, and it is not fooled by a local hosts file.
 * Answers are cached briefly so the checklist page stays quick.
 */
class DnsLookup
{
    private const CACHE_PREFIX = 'gadya-cms.dns.';

    private const RESOLVERS = [
        'https://cloudflare-dns.com/dns-query',
        'https://dns.google/resolve',
    ];

    /** Numeric record types, as DNS-over-HTTPS reports them. */
    private const TYPES = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'CAA' => 257];

    /**
     * The values of one record type, e.g. ["165.245.152.137"] for A or the
     * unquoted strings for TXT.
     *
     * @return list<string>
     */
    public function values(string $host, string $type): array
    {
        if (! $this->isPublic($host) || ! isset(self::TYPES[$type])) {
            return [];
        }

        $key = self::CACHE_PREFIX.$host.'.'.$type;
        $this->remember($key);

        return Cache::remember($key, now()->addMinutes(10), fn (): array => $this->ask($host, $type));
    }

    /** Forgets every answer, so a record added a minute ago shows up. */
    public function forget(): void
    {
        foreach ((array) Cache::get(self::CACHE_PREFIX.'keys', []) as $key) {
            Cache::forget($key);
        }

        Cache::forget(self::CACHE_PREFIX.'keys');
    }

    /** An address on the public internet, not an IP, localhost or a .test site. */
    public function isPublic(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/\.[a-z][a-z0-9-]*$/i', $host) !== 1) {
            return false;
        }

        return preg_match('/\.(test|local|localhost|invalid|example|internal)$/i', $host) !== 1;
    }

    /**
     * @return list<string>
     */
    private function ask(string $host, string $type): array
    {
        foreach (self::RESOLVERS as $resolver) {
            $response = rescue(fn () => Http::timeout(4)
                ->accept('application/dns-json')
                ->get($resolver, ['name' => $host, 'type' => $type]), null, report: false);

            if ($response === null || ! $response->successful()) {
                continue;
            }

            return collect((array) $response->json('Answer', []))
                ->where('type', self::TYPES[$type])
                ->map(fn (array $answer): string => $this->clean($type, (string) ($answer['data'] ?? '')))
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    private function clean(string $type, string $data): string
    {
        return match ($type) {
            // "v=spf1 " "include:x ~all" arrives as quoted chunks of one string.
            'TXT' => implode('', array_map(fn (string $chunk): string => stripslashes($chunk), preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches) ? $matches[1] : [$data])),
            'CNAME', 'NS' => rtrim($data, '.'),
            default => $data,
        };
    }

    private function remember(string $key): void
    {
        $keys = (array) Cache::get(self::CACHE_PREFIX.'keys', []);

        if (! in_array($key, $keys, true)) {
            Cache::forever(self::CACHE_PREFIX.'keys', [...$keys, $key]);
        }
    }
}
