<?php

namespace Gadya\Cms\Search;

use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Seo\SitemapEntries;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lighthouse, by way of Google's PageSpeed Insights API, which runs it on
 * Google's machines against the live site. Works without a key at a low
 * rate; a key (free, from Google Cloud) lifts the quota.
 */
class PageSpeed
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(
        private readonly Options $options,
        private readonly SiteContext $siteContext,
        private readonly SitemapEntries $sitemap,
    ) {}

    public function key(): ?string
    {
        return $this->options->getSecret('pagespeed.key');
    }

    public function check(string $url, string $strategy = 'mobile'): PageScore
    {
        $response = Http::timeout(120)->get(self::ENDPOINT, array_filter([
            'url' => $url,
            'strategy' => $strategy,
            'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
            'key' => $this->key(),
        ]));

        if (! $response->successful()) {
            throw new RuntimeException('PageSpeed answered '.$response->status().': '.($response->json('error.message') ?? 'no detail'));
        }

        $categories = (array) $response->json('lighthouseResult.categories', []);
        $audits = (array) $response->json('lighthouseResult.audits', []);
        $score = fn (string $name): ?int => isset($categories[$name]['score']) ? (int) round($categories[$name]['score'] * 100) : null;

        $opportunities = collect($audits)
            ->filter(fn ($audit): bool => is_array($audit) && ($audit['details']['type'] ?? null) === 'opportunity' && ($audit['details']['overallSavingsMs'] ?? 0) > 100)
            ->sortByDesc(fn (array $audit): float => (float) $audit['details']['overallSavingsMs'])
            ->take(5)
            ->map(fn (array $audit): array => ['title' => (string) $audit['title'], 'savings_ms' => (int) $audit['details']['overallSavingsMs']])
            ->values()
            ->all();

        return PageScore::query()->create([
            'site_id' => $this->siteContext->id(),
            'path' => '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/'),
            'strategy' => $strategy,
            'performance' => $score('performance'),
            'accessibility' => $score('accessibility'),
            'best_practices' => $score('best-practices'),
            'seo' => $score('seo'),
            'lcp_ms' => isset($audits['largest-contentful-paint']['numericValue']) ? (int) $audits['largest-contentful-paint']['numericValue'] : null,
            'cls' => isset($audits['cumulative-layout-shift']['numericValue']) ? (float) $audits['cumulative-layout-shift']['numericValue'] : null,
            'opportunities' => $opportunities,
            'checked_at' => now(),
        ]);
    }

    /**
     * Check the first few public pages - the home page and the most
     * important ones - rather than the whole sitemap, which would burn
     * the quota on the privacy policy.
     *
     * @return Collection<int, PageScore>
     */
    public function checkSite(int $limit = 5, string $strategy = 'mobile'): Collection
    {
        return collect($this->sitemap->all())
            ->take($limit)
            ->map(fn (array $entry): PageScore => $this->check($entry['loc'], $strategy));
    }

    /**
     * The latest score per page.
     *
     * @return Collection<int, PageScore>
     */
    public function latest(string $strategy = 'mobile'): Collection
    {
        return PageScore::query()
            ->where('site_id', $this->siteContext->id())
            ->where('strategy', $strategy)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->get()
            ->unique('path')
            ->sortBy('performance')
            ->values();
    }
}
