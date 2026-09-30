<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\PageView;
use Gadya\Cms\Support\SiteTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the page views an uptime monitor (or any script) left behind
 * before the bot filter knew about it: one "visitor" loading the same page
 * hundreds of times in a day. The rule is deliberately narrow, so a real
 * person, or a busy page with many visitors, is never touched.
 */
class CleanMonitorViewsCommand extends Command
{
    protected $signature = 'gadya-cms:analytics:clean-monitors
        {--force : Delete the views found (the default is a dry run)}
        {--days= : Only look at this many days back (default: everything retained)}
        {--min-views=200 : A visitor needs at least this many views in a day}
        {--path= : Only look at this path}';

    protected $description = 'Find (and with --force remove) past page views made by monitors: one visitor, one page, hundreds of times a day';

    /** The share of a visitor's views that must be one path. */
    private const SAME_PATH_SHARE = 0.95;

    public function handle(SiteTimezone $timezone): int
    {
        $minViews = max(2, (int) $this->option('min-views'));
        $since = $this->option('days') !== null && $this->option('days') !== ''
            ? $timezone->startOfLocalDay($timezone->now()->subDays(max(1, (int) $this->option('days')) - 1))
            : null;

        $groups = $this->findMonitors($timezone, $minViews, $since, filled($this->option('path')) ? (string) $this->option('path') : null);

        if ($groups === []) {
            $this->info('No monitor-like visitors found.');
            $this->note();

            return self::SUCCESS;
        }

        $this->table(
            ['Day', 'Site', 'Visitor', 'Path', 'Views'],
            array_map(fn (array $group): array => [$group['day'], $group['site_id'] ?? '-', substr($group['visitor_hash'], 0, 10), $group['path'], $group['views']], $groups),
        );

        $views = array_sum(array_column($groups, 'views'));
        $visitors = count($groups);

        if (! $this->option('force')) {
            $this->info("Dry run: {$views} views from {$visitors} visitor-days would be removed. Run again with --force to remove them.");
            $this->note();

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($groups as $group) {
            $removed += $this->remove($group);
        }

        $this->info("Removed {$removed} views from {$visitors} visitor-days.");
        $this->note();

        return self::SUCCESS;
    }

    private function note(): void
    {
        $this->line('The views the portal made before its user agent was renamed can be removed this way.');
    }

    /**
     * @return list<array{day: string, site_id: int|null, visitor_hash: string, path: string, views: int, start: string, end: string}>
     */
    private function findMonitors(SiteTimezone $timezone, int $minViews, ?\DateTimeInterface $since, ?string $path): array
    {
        $found = [];
        $counts = PageView::query()
            ->when($since, fn ($query) => $query->where('viewed_at', '>=', $since))
            ->selectRaw('site_id, visitor_hash, min(viewed_at) as first_at, max(viewed_at) as last_at, count(*) as views')
            ->groupBy('site_id', 'visitor_hash');

        /*
         * Grouping by local day happens here rather than in SQL, so the
         * same answer comes back on SQLite and MySQL across a change of
         * clocks. A first pass finds visitor hashes with enough views in
         * total to be worth looking at.
         */
        $candidates = $counts->havingRaw('count(*) >= ?', [$minViews])->get();

        foreach ($candidates as $candidate) {
            $views = PageView::query()
                ->where('visitor_hash', $candidate->visitor_hash)
                ->when($candidate->site_id === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->where('site_id', $candidate->site_id))
                ->when($since, fn ($query) => $query->where('viewed_at', '>=', $since))
                ->get(['path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'viewed_at']);

            foreach ($views->groupBy(fn (PageView $view): string => $timezone->format($view->viewed_at, 'Y-m-d')) as $day => $dayViews) {
                $total = $dayViews->count();

                if ($total < $minViews) {
                    continue;
                }

                $byPath = $dayViews->countBy('path')->sortDesc();
                $topPath = (string) $byPath->keys()->first();

                if ($path !== null && $topPath !== $path) {
                    continue;
                }

                if ($byPath->first() / $total < self::SAME_PATH_SHARE) {
                    continue;
                }

                if ($dayViews->pluck('referrer_host')->unique()->count() > 1
                    || $dayViews->contains(fn (PageView $view): bool => filled($view->utm_source) || filled($view->utm_medium) || filled($view->utm_campaign))) {
                    continue;
                }

                $found[] = [
                    'day' => (string) $day,
                    'site_id' => $candidate->site_id === null ? null : (int) $candidate->site_id,
                    'visitor_hash' => (string) $candidate->visitor_hash,
                    'path' => $topPath,
                    'views' => $total,
                    'start' => $timezone->startOfLocalDay((string) $day)->toDateTimeString(),
                    'end' => $timezone->endOfLocalDay((string) $day)->toDateTimeString(),
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => [$a['day'], $a['visitor_hash']] <=> [$b['day'], $b['visitor_hash']]);

        return $found;
    }

    /**
     * @param  array{site_id: int|null, visitor_hash: string, start: string, end: string}  $group
     */
    private function remove(array $group): int
    {
        return DB::transaction(function () use ($group): int {
            $removed = 0;

            do {
                $ids = PageView::query()
                    ->where('visitor_hash', $group['visitor_hash'])
                    ->when($group['site_id'] === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->where('site_id', $group['site_id']))
                    ->whereBetween('viewed_at', [$group['start'], $group['end']])
                    ->limit(500)
                    ->pluck('id');

                $removed += PageView::query()->whereKey($ids)->delete();
            } while ($ids->isNotEmpty());

            return $removed;
        });
    }
}
