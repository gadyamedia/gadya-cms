<?php

namespace Gadya\Cms\Search;

use Carbon\CarbonImmutable;
use Closure;
use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google Search Console through the Gadya Media portal.
 *
 * The portal is the one that signs in with Google and holds the token, so
 * a client needs no key, no service account and no sharing: they press a
 * button, choose their Google account, and the numbers appear. This site
 * only ever talks to the portal, over the same signed connect API as the
 * rest of the CMS, and never sees a Google credential.
 *
 * Nothing here throws into a screen. A portal that is down, slow or has
 * not set Google up answers "unavailable" (null, or a plain message), and
 * the page carries on. Both reads are kept for fifteen minutes, so a
 * Livewire redraw is never a call to the portal; the explicit refresh, and
 * anything that changes the connection, clears them.
 */
class PortalSearchConsole
{
    public const PATH = '/api/connect/v1/search-console';

    public const STATUS_KEY = 'gadya-cms.search-console.status';

    public const PERFORMANCE_KEY = 'gadya-cms.search-console.performance.';

    /** How long an answer is believed. */
    public const CACHE_SECONDS = 900;

    /** A failed call is remembered briefly, so a portal that is down is not asked on every redraw. */
    public const FAILURE_SECONDS = 60;

    public const NOT_CONNECTED = 'not_connected';

    public const CONNECTED = 'connected';

    public const NEEDS_RECONNECT = 'needs_reconnect';

    public const NO_PROPERTY = 'no_property';

    /** The ranges the portal keeps lists for; any other is answered from the nearest. */
    public const RANGES = [7, 28, 90];

    public function __construct(private readonly PortalClient $portal) {}

    /** Only a site paired with the portal can use it. */
    public function paired(): bool
    {
        return Connection::current() !== null;
    }

    /** Paired, and the portal has Google set up. */
    public function available(): bool
    {
        return ($this->status()['available'] ?? false) === true;
    }

    /**
     * Where the connection stands, or null when the portal could not be
     * asked (not paired, down, or an answer that made no sense).
     *
     * @return array{available: bool, status: string, source: string|null, google_email: string|null, property: string|null, connected_at: string|null, last_synced_at: string|null, last_error: string|null}|null
     */
    public function status(bool $fresh = false): ?array
    {
        return $this->cached(self::STATUS_KEY, $fresh, function (Connection $connection): ?array {
            $response = $this->portal->send($connection, 'GET', self::PATH);

            if (! $response->successful()) {
                return null;
            }

            $data = (array) ($response->json('data') ?? $response->json());

            return [
                'available' => (bool) ($data['available'] ?? false),
                'status' => $this->knownStatus($data['status'] ?? null),
                'source' => is_string($data['source'] ?? null) ? $data['source'] : null,
                'google_email' => $this->text($data['google_email'] ?? null),
                'property' => $this->text($data['property'] ?? null),
                'connected_at' => $this->text($data['connected_at'] ?? null),
                'last_synced_at' => $this->text($data['last_synced_at'] ?? null),
                'last_error' => $this->text($data['last_error'] ?? null),
            ];
        });
    }

    /** Connected, or was and needs signing in again: either way there is data to show. */
    public function hasData(): bool
    {
        return in_array($this->status()['status'] ?? null, [self::CONNECTED, self::NEEDS_RECONNECT], true);
    }

    /**
     * Where to send the browser so Google can ask for permission. The portal
     * brings the person back to `$returnUrl` with `?google=` saying how it went.
     *
     * @return array{url: string|null, error: string|null}
     */
    public function connectUrl(string $returnUrl, ?string $loginHint = null): array
    {
        $connection = Connection::current();

        if ($connection === null) {
            return ['url' => null, 'error' => 'This site is not connected to Gadya Media yet, so it cannot sign in to Google for you. You can use your own Google service account instead, below.'];
        }

        try {
            $response = $this->portal->send($connection, 'POST', self::PATH.'/connect', array_filter(['return_url' => $returnUrl, 'login_hint' => $loginHint]));
        } catch (Throwable) {
            return ['url' => null, 'error' => $this->unreachable()];
        }

        if ($response->status() === 503) {
            return ['url' => null, 'error' => 'Google sign-in is not switched on at Gadya Media yet. Please email us and we will sort it out; you can use your own Google service account meanwhile.'];
        }

        if ($response->status() === 422) {
            return ['url' => null, 'error' => 'Gadya Media would not take this address. Google can only be connected from the live https site, not from a test or local copy.'];
        }

        $url = $response->json('data.url');

        if (! $response->successful() || ! is_string($url) || $url === '') {
            return ['url' => null, 'error' => $this->refused($response)];
        }

        return ['url' => $url, 'error' => null];
    }

    /**
     * Let go of Google: the portal revokes the token and deletes what it kept.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function disconnect(): array
    {
        $result = $this->act('DELETE', self::PATH);

        $this->forget();

        return $result;
    }

    /**
     * Ask the portal to pull fresh numbers now. It allows one a few minutes.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function sync(): array
    {
        $result = $this->act('POST', self::PATH.'/sync', [
            409 => 'Connect Google Search Console first, then there is something to refresh.',
            429 => 'Gadya Media refreshed a moment ago. Give it a few minutes and try again.',
        ]);

        $this->forget();

        return $result;
    }

    /**
     * The last `$days` days (7 to 90), in the shapes the CMS shows: click
     * through rates as fractions, every day present, readable names.
     *
     * @return array{days: int, property: string|null, from: string|null, to: string|null, synced_at: string|null, totals: array{clicks: int, impressions: int, ctr: float, position: float}, previous: array{clicks: int, impressions: int, ctr: float, position: float}, changes: array{clicks: float|null, impressions: float|null, ctr: float|null, position: float|null}, daily: list<array{date: string, clicks: int, impressions: int, ctr: float, position: float}>, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pages: list<array{page: string, clicks: int, impressions: int, ctr: float, position: float}>, countries: list<array{code: string, name: string, clicks: int, impressions: int}>, devices: list<array{device: string, label: string, clicks: int, impressions: int}>}|null
     */
    public function performance(int $days = 28, bool $fresh = false): ?array
    {
        $days = max(7, min(90, $days));

        return $this->cached(self::PERFORMANCE_KEY.$days, $fresh, function (Connection $connection) use ($days): ?array {
            $response = $this->portal->send($connection, 'GET', self::PATH.'/performance?'.http_build_query(['days' => $days]));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json('data');

            return is_array($data) ? $this->shape($data, $days) : null;
        });
    }

    /** Clear what is kept, so the next read asks the portal. */
    public function forget(): void
    {
        Cache::forget(self::STATUS_KEY);

        foreach (range(7, 90) as $days) {
            Cache::forget(self::PERFORMANCE_KEY.$days);
        }
    }

    /**
     * What to tell the person when Google sends them back with `?google=failed`.
     */
    public static function failureMessage(?string $reason, string $host): string
    {
        return match ($reason) {
            'denied' => 'You chose not to share. Nothing was connected.',
            'no_property' => "That Google account has no Search Console property for {$host}. Add and verify the site at search.google.com/search-console, or sign in with another account.",
            'scope' => 'Google needs to be allowed to show us your search data: tick the Search Console box when you sign in.',
            'identity', 'email' => 'Google did not tell us which account you signed in with, so nothing was connected. Please try again.',
            'exchange', 'token' => 'Google signed you in but would not hand over access. Nothing was connected; please try again.',
            default => 'Something went wrong while connecting to Google. Nothing was connected; please try again.',
        };
    }

    /**
     * One read from the portal, remembered, including the failure. The value
     * is wrapped because the cache cannot tell a stored null from a miss.
     *
     * @param  Closure(Connection): (array<string, mixed>|null)  $fetch
     * @return array<string, mixed>|null
     */
    private function cached(string $key, bool $fresh, Closure $fetch): ?array
    {
        if ($fresh) {
            Cache::forget($key);
        }

        $kept = Cache::get($key);

        if (is_array($kept) && array_key_exists('value', $kept)) {
            return $kept['value'];
        }

        $connection = Connection::current();

        if ($connection === null) {
            return null;
        }

        try {
            $value = $fetch($connection);
        } catch (Throwable) {
            $value = null;
        }

        Cache::put($key, ['value' => $value], now()->addSeconds($value === null ? self::FAILURE_SECONDS : self::CACHE_SECONDS));

        return $value;
    }

    /**
     * @param  array<int, string>  $messages  plain words for particular statuses
     * @return array{ok: bool, error: string|null}
     */
    private function act(string $method, string $path, array $messages = []): array
    {
        $connection = Connection::current();

        if ($connection === null) {
            return ['ok' => false, 'error' => 'This site is not connected to Gadya Media yet.'];
        }

        try {
            $response = $this->portal->send($connection, $method, $path);
        } catch (Throwable) {
            return ['ok' => false, 'error' => $this->unreachable()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => $messages[$response->status()] ?? $this->refused($response)];
    }

    private function unreachable(): string
    {
        return 'We could not reach Gadya Media just now. Please try again in a minute.';
    }

    private function refused(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== ''
            ? 'Gadya Media said: '.Str::limit($message, 200)
            : 'Gadya Media could not do that just now (HTTP '.$response->status().'). Please try again in a minute.';
    }

    private function knownStatus(mixed $status): string
    {
        return in_array($status, [self::CONNECTED, self::NEEDS_RECONNECT, self::NO_PROPERTY], true) ? $status : self::NOT_CONNECTED;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function shape(array $data, int $days): array
    {
        $totals = $this->metrics((array) ($data['totals'] ?? []));
        $previous = $this->metrics((array) ($data['previous'] ?? []));

        return [
            'days' => $days,
            'property' => $this->text($data['property'] ?? null),
            'from' => $this->text($data['from'] ?? null),
            'to' => $this->text($data['to'] ?? null),
            'synced_at' => $this->text($data['synced_at'] ?? null),
            'totals' => $totals,
            'previous' => $previous,
            'changes' => [
                'clicks' => $this->relative($totals['clicks'], $previous['clicks']),
                'impressions' => $this->relative($totals['impressions'], $previous['impressions']),
                'ctr' => $this->relative($totals['ctr'], $previous['ctr']),
                /* Places moved, not a percentage: negative is better, as a lower number ranks higher. */
                'position' => $previous['position'] > 0 && $totals['position'] > 0 ? round($totals['position'] - $previous['position'], 1) : null,
            ],
            'daily' => $this->daily((array) ($data['daily'] ?? []), $this->text($data['from'] ?? null), $this->text($data['to'] ?? null)),
            'queries' => array_values(array_map(fn (array $row): array => ['query' => (string) ($row['query'] ?? '')] + $this->metrics($row), $this->rows($data['queries'] ?? []))),
            'pages' => array_values(array_map(fn (array $row): array => ['page' => (string) ($row['page'] ?? '')] + $this->metrics($row), $this->rows($data['pages'] ?? []))),
            'countries' => array_values(array_map(fn (array $row): array => [
                'code' => (string) ($row['country'] ?? ''),
                'name' => CountryCodes::name((string) ($row['country'] ?? '')),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
            ], $this->rows($data['countries'] ?? []))),
            'devices' => array_values(array_map(fn (array $row): array => [
                'device' => (string) ($row['device'] ?? ''),
                'label' => $this->deviceLabel((string) ($row['device'] ?? '')),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
            ], $this->rows($data['devices'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{clicks: int, impressions: int, ctr: float, position: float}
     */
    private function metrics(array $row): array
    {
        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** Percent change, or null when there was nothing before to compare with. */
    private function relative(float|int $now, float|int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    /**
     * The portal keeps only days Google reported on; a chart wants every day
     * in the range, so the quiet ones are filled in as zeros.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{date: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    private function daily(array $rows, ?string $from, ?string $to): array
    {
        $byDate = [];

        foreach ($this->rows($rows) as $row) {
            $date = substr((string) ($row['date'] ?? ''), 0, 10);

            if ($date !== '') {
                $byDate[$date] = $this->metrics($row);
            }
        }

        if ($from === null || $to === null) {
            ksort($byDate);

            return array_values(array_map(fn (string $date): array => ['date' => $date] + $byDate[$date], array_keys($byDate)));
        }

        $filled = [];
        $day = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        while ($day->lte($end) && count($filled) < 400) {
            $date = $day->toDateString();
            $filled[] = ['date' => $date] + ($byDate[$date] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0]);
            $day = $day->addDay();
        }

        return $filled;
    }

    private function deviceLabel(string $device): string
    {
        return match (strtoupper($device)) {
            'MOBILE' => 'Phones',
            'DESKTOP' => 'Computers',
            'TABLET' => 'Tablets',
            default => Str::headline(strtolower($device)),
        };
    }
}
