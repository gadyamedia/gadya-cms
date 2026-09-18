<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\AuditLog;
use Gadya\Cms\Models\BrokenLink;
use Illuminate\Console\Command;

/**
 * The activity log and the broken-link list both answer questions about
 * the recent past; neither is worth keeping for ever.
 */
class PruneActivityCommand extends Command
{
    protected $signature = 'gadya-cms:prune-activity {--days= : Delete entries older than this}';

    protected $description = 'Delete old activity entries and broken links that have been fixed';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('gadya-cms.activity.keep_days', 180));
        $cutoff = now()->subDays(max(1, $days));

        $activity = AuditLog::query()->where('created_at', '<', $cutoff)->delete();

        $links = BrokenLink::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays(max(1, (int) config('gadya-cms.broken_links.keep_days', 180))))
            ->delete();

        $this->info("Pruned {$activity} activity entries and {$links} links that were fixed long ago.");

        return self::SUCCESS;
    }
}
