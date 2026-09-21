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
            'failures' => $this->failures($audits, $categories),
            'checked_at' => now(),
        ]);
    }

    /**
     * Every audit the page failed, with the elements it failed on: the
     * selector so a person can find it, and the snippet so she can
     * recognise it. Manual audits and ones that do not apply are not
     * failures and are left out.
     *
     * @param  array<string, mixed>  $audits
     * @param  array<string, mixed>  $categories
     * @return list<array{id: string, title: string, description: string, category: string, elements: list<array{selector: string, snippet: string, explanation: string}>}>
     */
    private function failures(array $audits, array $categories): array
    {
        $categoryOf = [];

        foreach ($categories as $name => $category) {
            foreach ((array) ($category['auditRefs'] ?? []) as $ref) {
                if (is_array($ref) && isset($ref['id'])) {
                    $categoryOf[(string) $ref['id']] = (string) $name;
                }
            }
        }

        return collect($audits)
            ->filter(fn ($audit): bool => is_array($audit)
                && is_numeric($audit['score'] ?? null)
                && (float) $audit['score'] < 1
                && ($audit['scoreDisplayMode'] ?? '') !== 'informative')
            ->map(fn (array $audit, string $id): array => [
                'id' => $id,
                'title' => (string) ($audit['title'] ?? $id),
                'description' => $this->plain((string) ($audit['description'] ?? '')),
                'category' => $categoryOf[$id] ?? 'other',
                'elements' => $this->elements($audit),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return list<array{selector: string, snippet: string, explanation: string}>
     */
    private function elements(array $audit): array
    {
        return collect((array) ($audit['details']['items'] ?? []))
            ->map(function ($item): ?array {
                $node = is_array($item) ? ($item['node'] ?? null) : null;

                if (! is_array($node)) {
                    return null;
                }

                return [
                    'selector' => (string) ($node['selector'] ?? ''),
                    'snippet' => (string) ($node['snippet'] ?? ''),
                    'explanation' => $this->plain((string) ($node['explanation'] ?? $item['explanation'] ?? '')),
                ];
            })
            ->filter()
            ->take(10)
            ->values()
            ->all();
    }

    /** Lighthouse writes Markdown links into its prose; the panel wants words. */
    private function plain(string $text): string
    {
        return trim((string) preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/\s+/'], ['$1', ' '], $text));
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
