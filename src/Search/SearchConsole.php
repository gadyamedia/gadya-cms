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
 * they landed on. Fetched from the Search Console API on a schedule and
 * shown on the dashboard beside the site's own numbers.
 */
class SearchConsole
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(
        private readonly Options $options,
        private readonly SiteContext $siteContext,
    ) {}

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
        return $this->property() !== null && $this->account() !== null;
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
