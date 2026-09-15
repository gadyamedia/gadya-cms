<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Revision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Restores a past revision as both the draft and the live site, and records
 * the restore as a revision of its own so the history stays truthful.
 */
class RevertSiteContent
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PublishSiteContent $publishSiteContent,
    ) {}

    public function handle(Revision $revision, ?Authenticatable $user = null): void
    {
        DB::transaction(function () use ($revision, $user): void {
            $this->repository->saveDraft($revision->snapshot);

            $this->publishSiteContent->handle($user, "Reverted to revision {$revision->id}");
        });
    }
}
