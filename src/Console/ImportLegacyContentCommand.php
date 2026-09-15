<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves a site off the single-document `site_contents` table this package
 * replaces. It is safe to run more than once and it never drops the old
 * tables: the operator does that once she is satisfied the site is intact.
 */
class ImportLegacyContentCommand extends Command
{
    protected $signature = 'gadya-cms:import-legacy-content';

    protected $description = 'Import content from the pre-Filament site_contents tables';

    public function handle(SiteContentRepository $repository, SiteContext $siteContext): int
    {
        $siteId = $siteContext->id();

        if ($siteId === null) {
            $this->error('No site could be resolved. Run the migrations first.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('site_contents')) {
            $this->warn('No legacy [site_contents] table found; nothing to import.');

            return self::SUCCESS;
        }

        $published = $this->readDocument('site');
        $draft = $this->readDocument('site:draft');

        if ($published === []) {
            $this->warn('The legacy [site_contents] table is empty; nothing to import.');

            return self::SUCCESS;
        }

        /*
         * The draft is written first and then published, which copies it
         * onto the published columns. Writing the real draft afterwards
         * leaves the two correctly out of step again if they were.
         */
        $repository->saveDraft($published);
        app(PublishSiteContent::class)->handle(null, 'Imported from the previous CMS');

        if ($draft !== [] && $draft !== $published) {
            $repository->saveDraft($draft);
        }

        $this->importRevisions($siteId);
        $this->importMedia($siteId);

        $repository->flushPublishedCache();

        $this->info('Legacy content imported.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function readDocument(string $key): array
    {
        $value = DB::table('site_contents')->where('key', $key)->value('value');

        if (! is_string($value)) {
            return is_array($value) ? $value : [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function importRevisions(int $siteId): void
    {
        if (! Schema::hasTable('site_content_revisions')) {
            return;
        }

        $imported = 0;

        foreach (DB::table('site_content_revisions')->orderBy('id')->cursor() as $row) {
            $snapshot = is_string($row->value) ? json_decode($row->value, true) : $row->value;

            if (! is_array($snapshot)) {
                continue;
            }

            Revision::query()->firstOrCreate(
                ['site_id' => $siteId, 'published_at' => $row->published_at, 'label' => $row->label],
                ['snapshot' => $snapshot, 'published_by' => $row->published_by],
            );

            $imported++;
        }

        $this->line("Imported {$imported} revisions.");
    }

    private function importMedia(int $siteId): void
    {
        if (! Schema::hasTable('media_items')) {
            return;
        }

        $imported = 0;

        foreach (DB::table('media_items')->orderBy('id')->cursor() as $row) {
            $media = Media::query()->firstOrCreate(
                ['filename' => $row->filename],
                [
                    'site_id' => $siteId,
                    'original_name' => $row->original_name,
                    'disk' => $row->disk,
                    'path' => $row->path,
                    'thumbnail_path' => $row->thumbnail_path,
                    'mime_type' => $row->mime_type,
                    'width' => $row->width,
                    'height' => $row->height,
                    'size' => $row->size,
                    'alt_text' => $row->alt_text,
                    'uploaded_by' => $row->uploaded_by,
                    'is_legacy' => (bool) $row->is_legacy,
                    'status' => $row->status,
                ],
            );

            if ($media->wasRecentlyCreated) {
                $imported++;
            }
        }

        $this->line("Imported {$imported} photos.");
    }
}
