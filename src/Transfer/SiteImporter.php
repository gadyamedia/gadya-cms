<?php

namespace Gadya\Cms\Transfer;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Option;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Models\Setting;
use Gadya\Cms\Redirects\RedirectMap;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The other half of SiteExporter. Rows are matched by their natural key
 * (slug, setting key, filename, old path) and updated in place, so an
 * import can be repeated; with `replace` everything of this site's is
 * removed first.
 */
class SiteImporter
{
    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly SiteContentRepository $repository,
    ) {}

    /**
     * @return array<string, int> what was written, by kind
     */
    public function fromFile(string $path, bool $replace = false): array
    {
        if (! File::exists($path)) {
            throw new RuntimeException("{$path} does not exist.");
        }

        if (str_ends_with(strtolower($path), '.zip')) {
            return $this->fromZip($path, $replace);
        }

        $data = json_decode((string) File::get($path), true);

        if (! is_array($data)) {
            throw new RuntimeException("{$path} is not a site export.");
        }

        return $this->import($data, $replace);
    }

    /**
     * @return array<string, int>
     */
    private function fromZip(string $path, bool $replace): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is not installed.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Could not open {$path}.");
        }

        $json = $zip->getFromName('site.json');
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)) {
            $zip->close();

            throw new RuntimeException("{$path} has no site.json.");
        }

        $counts = $this->import($data, $replace);
        $files = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_starts_with($name, 'media/') || str_ends_with($name, '/')) {
                continue;
            }

            Storage::disk((string) config('gadya-cms.media.disk', 'public'))->put(substr($name, 6), (string) $zip->getFromIndex($i));
            $files++;
        }

        $zip->close();

        return [...$counts, 'files' => $files];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    public function import(array $data, bool $replace = false): array
    {
        if ((int) ($data['format'] ?? 0) !== SiteExporter::FORMAT) {
            throw new RuntimeException('This export was written by a different version of the CMS.');
        }

        $siteId = $this->siteContext->id();

        if ($siteId === null) {
            throw new RuntimeException('No site to import into; run the migrations first.');
        }

        $counts = ['pages' => 0, 'settings' => 0, 'posts' => 0, 'redirects' => 0, 'options' => 0, 'media' => 0];

        DB::transaction(function () use ($data, $replace, $siteId, &$counts): void {
            if ($replace) {
                /*
                 * Force, not soft: a trashed page still owns its address,
                 * and an import that replaces everything must be able to
                 * write to it.
                 */
                Page::withTrashed()->where('site_id', $siteId)->forceDelete();
                Post::withTrashed()->where('site_id', $siteId)->forceDelete();

                foreach ([Setting::class, Redirect::class, Media::class] as $model) {
                    $model::query()->where('site_id', $siteId)->delete();
                }

                Option::query()->where('site_id', $siteId)->whereNotIn('key', ['ai.key'])->delete();
            }

            foreach ((array) ($data['media'] ?? []) as $row) {
                Media::query()->updateOrCreate(['filename' => $row['filename']], [...$row, 'site_id' => $siteId]);
                $counts['media']++;
            }

            foreach ((array) ($data['pages'] ?? []) as $row) {
                /*
                 * A page in the trash under this address comes back: the
                 * export says the site has that page, and `deleted_at` is
                 * not fillable, so the restore is explicit.
                 */
                $page = Page::withTrashed()->updateOrCreate(['site_id' => $siteId, 'slug' => $row['slug']], $row);

                if ($page->trashed()) {
                    $page->restore();
                }
                $counts['pages']++;
            }

            foreach ((array) ($data['settings'] ?? []) as $row) {
                Setting::query()->updateOrCreate(['site_id' => $siteId, 'key' => $row['key']], $row);
                $counts['settings']++;
            }

            foreach ((array) ($data['posts'] ?? []) as $row) {
                Post::withTrashed()->updateOrCreate(['site_id' => $siteId, 'slug' => $row['slug']], [...$row, 'deleted_at' => null]);
                $counts['posts']++;
            }

            foreach ((array) ($data['redirects'] ?? []) as $row) {
                Redirect::query()->updateOrCreate(['site_id' => $siteId, 'from_path' => Redirect::normalise($row['from_path'])], [...$row, 'site_id' => $siteId]);
                $counts['redirects']++;
            }

            foreach ((array) ($data['options'] ?? []) as $row) {
                if (isset($row['value']['value']['encrypted'])) {
                    continue;
                }

                Option::query()->updateOrCreate(['site_id' => $siteId, 'key' => $row['key']], ['value' => $row['value']]);
                $counts['options']++;
            }
        });

        $this->repository->flushPublishedCache();
        app(RedirectMap::class)->flush();

        return $counts;
    }
}
