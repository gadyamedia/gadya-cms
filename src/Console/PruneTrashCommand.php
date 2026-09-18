<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Illuminate\Console\Command;

/**
 * Empties the trash of anything nobody came back for. A page is restorable
 * for as long as the retention window; after that it is gone for good,
 * because a trash that keeps everything is just a slower database.
 */
class PruneTrashCommand extends Command
{
    protected $signature = 'gadya-cms:prune-trash {--days= : Delete trashed pages and articles older than this}';

    protected $description = 'Delete trashed pages and articles past the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('gadya-cms.trash.keep_days', 30));
        $cutoff = now()->subDays(max(1, $days));

        $pages = Page::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
        $posts = Post::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        $this->info("Emptied {$pages} pages and {$posts} articles deleted before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
