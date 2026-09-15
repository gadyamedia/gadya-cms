<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Models\PageView;
use Illuminate\Console\Command;

/**
 * The other half of privacy-first analytics: keeping only what is still
 * useful. Numbers older than the retention window answer no question
 * anyone is asking, so they go.
 */
class PruneAnalyticsCommand extends Command
{
    protected $signature = 'gadya-cms:prune-analytics {--days= : Delete rows older than this many days}';

    protected $description = 'Delete page views and events past the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('gadya-cms.analytics.retention_days', 180));
        $cutoff = now()->subDays(max(1, $days));

        $views = PageView::query()->where('viewed_at', '<', $cutoff)->delete();
        $events = AnalyticsEvent::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$views} page views and {$events} events from before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
