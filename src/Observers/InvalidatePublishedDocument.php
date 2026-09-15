<?php

namespace Gadya\Cms\Observers;

use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the cached live document honest.
 *
 * Publishing flushes the cache itself, but that is not the only way the
 * published copy changes: deleting a page takes its published copy with it,
 * and the live site would otherwise keep serving the cached document for
 * ever - the page is gone, yet visitors still see it.
 *
 * Draft-only edits deliberately do not flush anything. Nothing the client
 * types should reach the live site before she publishes it.
 */
class InvalidatePublishedDocument
{
    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function updated(Model $model): void
    {
        if ($model->wasChanged('published')) {
            $this->flush();
        }
    }

    public function created(Model $model): void
    {
        if ($model->published !== null) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        DB::afterCommit(fn () => app(SiteContentRepository::class)->flushPublishedCache());
    }
}
