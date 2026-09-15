<?php

namespace Gadya\Cms\Analytics;

use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Models\PageView;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the dashboard shows, as plain arrays.
 *
 * Kept out of the Filament page so the numbers can be tested without
 * rendering anything, and so a second surface - an emailed summary, an
 * export - can ask the same questions later.
 */
class AnalyticsReport
{
    public function __construct(private readonly int $days = 30) {}

    public function for(int $days): self
    {
        return new self($days);
    }

    public function since(): Carbon
    {
        return now()->subDays($this->days)->startOfDay();
    }

    /**
     * Visitors in the last few minutes, and what they are looking at.
     *
     * @return array{count: int, pages: list<array{path: string, visitors: int}>, countries: list<array{code: string, flag: string, visitors: int}>, places: list<array{label: string, visitors: int}>}
     */
    public function live(): array
    {
        $minutes = max(1, (int) config('gadya-cms.analytics.live_minutes', 5));
        $since = now()->subMinutes($minutes);

        return [
            'count' => PageView::query()->since($since)->distinct()->count('visitor_hash'),
            'pages' => PageView::query()
                ->since($since)
                ->selectRaw('path, count(distinct visitor_hash) as visitors')
                ->groupBy('path')
                ->orderByDesc('visitors')
                ->limit(8)
                ->get()
                ->map(fn ($row): array => ['path' => $row->path, 'visitors' => (int) $row->visitors])
                ->all(),
            'countries' => PageView::query()
                ->since($since)
                ->whereNotNull('country')
                ->selectRaw('country, count(distinct visitor_hash) as visitors')
                ->groupBy('country')
                ->orderByDesc('visitors')
                ->limit(8)
                ->get()
                ->map(fn ($row): array => [
                    'code' => (string) $row->country,
                    'flag' => VisitorGeo::flag($row->country),
                    'visitors' => (int) $row->visitors,
                ])
                ->all(),
            'places' => PageView::query()
                ->since($since)
                ->whereNotNull('city')
                ->selectRaw('city, country, count(distinct visitor_hash) as visitors')
                ->groupBy('city', 'country')
                ->orderByDesc('visitors')
                ->limit(8)
                ->get()
                ->map(fn ($row): array => [
                    'label' => VisitorGeo::flag($row->country).' '.$row->city,
                    'visitors' => (int) $row->visitors,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function headline(): array
    {
        $since = $this->since();
        $views = PageView::query()->since($since);

        $visitors = (clone $views)->distinct()->count('visitor_hash');
        $phoneClicks = AnalyticsEvent::query()->since($since)->where('name', 'phone_click')->count();
        $enquiries = AnalyticsEvent::query()->since($since)
            ->whereIn('name', ['booking_start', 'lead_form_submit'])
            ->count();

        return [
            'views' => (clone $views)->count(),
            'visitors' => $visitors,
            'phone_clicks' => $phoneClicks,
            'enquiries' => $enquiries,
            'contacts' => $phoneClicks + $enquiries,
            'contact_rate' => $visitors > 0 ? round(($phoneClicks + $enquiries) / $visitors * 100, 1) : 0.0,
        ];
    }

    /**
     * Day-by-day traffic across the window, with empty days kept so the
     * chart shows a quiet Tuesday rather than closing the gap.
     *
     * @return list<array{day: string, views: int, visitors: int}>
     */
    public function daily(): array
    {
        $rows = PageView::query()
            ->since($this->since())
            ->get(['viewed_at', 'visitor_hash'])
            ->groupBy(fn (PageView $view): string => $view->viewed_at->toDateString());

        return collect(range($this->days - 1, 0))
            ->map(function (int $offset) use ($rows): array {
                $date = now()->subDays($offset);
                $day = $rows->get($date->toDateString());

                return [
                    'day' => $date->format('M j'),
                    'views' => $day?->count() ?? 0,
                    'visitors' => $day?->unique('visitor_hash')->count() ?? 0,
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int, object>
     */
    public function topPages(int $limit = 10): Collection
    {
        return PageView::query()
            ->since($this->since())
            ->selectRaw('path, count(*) as views, count(distinct visitor_hash) as visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function referrers(int $limit = 8): Collection
    {
        return PageView::query()
            ->since($this->since())
            ->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, count(distinct visitor_hash) as visitors')
            ->groupBy('referrer_host')
            ->orderByDesc('visitors')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function campaigns(int $limit = 8): Collection
    {
        return PageView::query()
            ->since($this->since())
            ->whereNotNull('utm_source')
            ->selectRaw('utm_source, utm_campaign, count(distinct visitor_hash) as visitors')
            ->groupBy('utm_source', 'utm_campaign')
            ->orderByDesc('visitors')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function events(): Collection
    {
        return AnalyticsEvent::query()
            ->since($this->since())
            ->selectRaw('name, count(*) as total')
            ->groupBy('name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Visitors per country across the window, keyed by the lowercase ISO
     * code a choropleth's SVG uses for its element ids.
     *
     * @return array<string, int>
     */
    public function countryTotals(): array
    {
        return PageView::query()
            ->since($this->since())
            ->whereNotNull('country')
            ->selectRaw('country, count(distinct visitor_hash) as visitors')
            ->groupBy('country')
            ->pluck('visitors', 'country')
            ->mapWithKeys(fn ($count, $code): array => [mb_strtolower((string) $code) => (int) $count])
            ->all();
    }

    /**
     * How people are holding the site. A party is planned on a phone far
     * more often than at a desk, and that changes what matters.
     *
     * @return list<array{label: string, visitors: int, share: float}>
     */
    public function devices(): array
    {
        $rows = PageView::query()
            ->since($this->since())
            ->whereNotNull('device_category')
            ->selectRaw('device_category, count(distinct visitor_hash) as visitors')
            ->groupBy('device_category')
            ->orderByDesc('visitors')
            ->get();

        $total = max(1, (int) $rows->sum('visitors'));

        return $rows->map(fn ($row): array => [
            'label' => ucfirst((string) $row->device_category),
            'visitors' => (int) $row->visitors,
            'share' => round($row->visitors / $total * 100),
        ])->all();
    }
}
