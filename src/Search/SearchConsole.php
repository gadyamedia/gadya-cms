<?php

namespace Gadya\Cms\Search;

use Gadya\Cms\Models\SearchSnapshot;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What Google says people searched for to find the site, and which pages
 * they landed on. Fetched on a schedule and kept as snapshots, so the
 * dashboard shows it beside the site's own numbers without waiting.
 *
 * It has two sources, and the rest of the CMS never needs to know which.
 * When the client has connected their Google account through the Gadya
 * Media portal, the numbers come from the portal. Otherwise they come
 * straight from Google with the client's own service account key, as they
 * always did. The portal wins when it has data; a site that has not
 * connected, is not paired, or whose portal cannot be reached simply falls
 * back to the key.
 */
class SearchConsole
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_SERVICE_ACCOUNT = 'service_account';

    public function __construct(
        private readonly Options $options,
        private readonly SiteContext $siteContext,
        private readonly PortalSearchConsole $portal,
    ) {}

    /**
     * Where the numbers come from: the portal when Google is connected
     * there, the client's own key when one is saved, or nowhere yet.
     */
    public function source(): ?string
    {
        if ($this->portal->hasData()) {
            return self::SOURCE_PORTAL;
        }

        return $this->hasOwnKey() ? self::SOURCE_SERVICE_ACCOUNT : null;
    }

    public function usesPortal(): bool
    {
        return $this->source() === self::SOURCE_PORTAL;
    }

    /** The client's own property and service account key, the advanced method. */
    public function hasOwnKey(): bool
    {
        return $this->property() !== null && $this->account() !== null;
    }

    public function property(): ?string
    {
        $property = $this->options->get('search.property');

        return is_string($property) && $property !== '' ? $property : null;
    }

    public function account(): ?GoogleServiceAccount
    {
        $json = $this->options->getSecret('search.service_account');

        return $json === null ? null : new GoogleServiceAccount($json);
    }

    public function isConfigured(): bool
    {
        return $this->source() !== null;
    }

    /**
     * @param  array{property?: string|null, service_account?: string|null}  $data
     */
    public function save(array $data): void
    {
        $this->options->set('search.property', $data['property'] ?? null);

        if (! empty($data['service_account'])) {
            (new GoogleServiceAccount((string) $data['service_account']))->credentials();
            $this->options->setSecret('search.service_account', (string) $data['service_account']);
        }
    }

    public function forget(): void
    {
        $this->options->forget('search.service_account');
    }

    /**
     * Pull the last `$days` days of queries and landing pages and keep them
     * as this period's snapshot, replacing the previous one.
     *
     * @return array{queries: int, pages: int}
     */
    public function fetch(int $days = 28): array
    {
        if ($this->usesPortal()) {
            return $this->fetchFromPortal($days);
        }

        $account = $this->account();
        $property = $this->property();

        if ($account === null || $property === null) {
            throw new RuntimeException('Search Console is not set up: a property and a service account key are needed.');
        }

        /*
         * Google's data lags by a couple of days, so the window ends the
         * day before yesterday rather than reporting zeros for today.
         */
        $end = now()->subDays(2)->toDateString();
        $start = now()->subDays(2 + $days)->toDateString();
        $token = $account->token([self::SCOPE]);
        $counts = [];

        foreach (['query' => 'queries', 'page' => 'pages'] as $dimension => $label) {
            $response = Http::withToken($token)->post(
                'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($property).'/searchAnalytics/query',
                ['startDate' => $start, 'endDate' => $end, 'dimensions' => [$dimension], 'rowLimit' => 25],
            );

            if (! $response->successful()) {
                throw new RuntimeException('Search Console answered '.$response->status().': '.($response->json('error.message') ?? 'no detail'));
            }

            $rows = (array) $response->json('rows', []);

            SearchSnapshot::query()->where('site_id', $this->siteContext->id())->where('kind', $dimension)->delete();

            foreach ($rows as $row) {
                SearchSnapshot::query()->create([
                    'site_id' => $this->siteContext->id(),
                    'kind' => $dimension,
                    'key' => mb_substr((string) ($row['keys'][0] ?? ''), 0, 500),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => (float) ($row['ctr'] ?? 0),
                    'position' => (float) ($row['position'] ?? 0),
                    'period_start' => $start,
                    'period_end' => $end,
                    'fetched_at' => now(),
                ]);
            }

            $counts[$label] = count($rows);
        }

        return $counts;
    }

    /**
     * The same snapshot, filled from the portal's stored numbers instead of
     * a call to Google: the portal has already done the asking.
     *
     * @return array{queries: int, pages: int}
     */
    private function fetchFromPortal(int $days): array
    {
        $performance = $this->portal->performance($days, fresh: true);

        if ($performance === null) {
            throw new RuntimeException('Gadya Media did not answer, so there is nothing new to keep. Try again in a minute.');
        }

        $to = $performance['to'] ?? now()->subDays(3)->toDateString();
        $from = $performance['from'] ?? now()->subDays(3 + $performance['days'])->toDateString();

        foreach (['query' => $performance['queries'], 'page' => $performance['pages']] as $kind => $rows) {
            SearchSnapshot::query()->where('site_id', $this->siteContext->id())->where('kind', $kind)->delete();

            foreach ($rows as $row) {
                SearchSnapshot::query()->create([
                    'site_id' => $this->siteContext->id(),
                    'kind' => $kind,
                    'key' => mb_substr($row[$kind], 0, 500),
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                    'period_start' => $from,
                    'period_end' => $to,
                    'fetched_at' => now(),
                ]);
            }
        }

        return ['queries' => count($performance['queries']), 'pages' => count($performance['pages'])];
    }

    /**
     * @return Collection<int, SearchSnapshot>
     */
    public function topQueries(int $limit = 10): Collection
    {
        return $this->snapshots('query', $limit);
    }

    /**
     * @return Collection<int, SearchSnapshot>
     */
    public function topPages(int $limit = 10): Collection
    {
        return $this->snapshots('page', $limit);
    }

    public function fetchedAt(): ?Carbon
    {
        return SearchSnapshot::query()->where('site_id', $this->siteContext->id())->max('fetched_at') !== null
            ? Carbon::parse(SearchSnapshot::query()->where('site_id', $this->siteContext->id())->max('fetched_at'))
            : null;
    }

    /**
     * @return array{clicks: int, impressions: int}
     */
    public function totals(): array
    {
        /* The portal knows the real totals; the snapshot only holds the top rows. */
        $performance = $this->usesPortal() ? $this->portal->performance(28) : null;

        if ($performance !== null && $performance['synced_at'] !== null) {
            return ['clicks' => $performance['totals']['clicks'], 'impressions' => $performance['totals']['impressions']];
        }

        $rows = $this->snapshots('query', 1000);

        return ['clicks' => (int) $rows->sum('clicks'), 'impressions' => (int) $rows->sum('impressions')];
    }

    /**
     * @return Collection<int, SearchSnapshot>
     */
    private function snapshots(string $kind, int $limit): Collection
    {
        return SearchSnapshot::query()
            ->where('site_id', $this->siteContext->id())
            ->where('kind', $kind)
            ->orderByDesc('clicks')
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get();
    }
}
