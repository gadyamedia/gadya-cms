<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Models\Revision;

class PruneOldRevisions
{
    public function handle(): void
    {
        $keep = max(1, (int) config('gadya-cms.revisions.keep', 30));

        $oldest = Revision::query()
            ->latest('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($oldest->isNotEmpty()) {
            Revision::query()->whereIn('id', $oldest)->delete();
        }
    }
}
