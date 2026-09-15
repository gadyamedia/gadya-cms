<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Models\Setting;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Makes the draft live by copying every draft column onto its published
 * twin, then records the whole document as a revision so any publish can be
 * rolled back.
 */
class PublishSiteContent
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly SiteContext $siteContext,
        private readonly PruneOldRevisions $pruneOldRevisions,
    ) {}

    public function handle(?Authenticatable $user = null, ?string $label = null): Revision
    {
        return DB::transaction(function () use ($user, $label): Revision {
            $siteId = $this->siteContext->id();
            $draft = $this->repository->draft();

            Page::query()->where('site_id', $siteId)->eachById(function (Page $page): void {
                if ($page->draft === null) {
                    $page->delete();

                    return;
                }

                $page->forceFill(['published' => $page->draft])->save();
            });

            Setting::query()->where('site_id', $siteId)->eachById(function (Setting $setting): void {
                $setting->forceFill(['published' => $setting->draft])->save();
            });

            $revision = Revision::query()->create([
                'site_id' => $siteId,
                'snapshot' => $draft,
                'published_by' => $user?->getAuthIdentifier(),
                'label' => $label,
                'published_at' => now(),
            ]);

            $this->pruneOldRevisions->handle();

            DB::afterCommit(fn () => $this->repository->flushPublishedCache());

            return $revision;
        });
    }
}
