<?php

namespace Gadya\Cms\Content;

use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Setting;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The site document - the single nested array every template, every
 * `@editable` path and the live editor address - assembled from, and written
 * back to, the CMS tables.
 *
 * Pages live one per row so Filament can list, sort and filter them like any
 * other resource; everything else in the document (the theme, the navigation,
 * the redirect table, the global contact details) is one settings row per
 * top-level key. Both carry a `draft` and a `published` copy, so the client
 * can edit the site freely and only make it live when she is ready.
 */
class SiteContentRepository
{
    public const CACHE_KEY = 'gadya-cms.document.published';

    /** The document key holding the per-slug page map. */
    public const PAGES_KEY = 'pages';

    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @return array<string, mixed>
     */
    public function published(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = $this->assemble('published');

            return $this->isEmptyDocument($stored) ? $this->defaults() : $stored;
        });
    }

    /**
     * The draft falls back per key, not wholesale: a page that has never
     * been published still resolves through to its published siblings
     * rather than blanking the rest of the document.
     *
     * @return array<string, mixed>
     */
    public function draft(): array
    {
        $draft = $this->assemble('draft');

        return $this->isEmptyDocument($draft) ? $this->published() : $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function forRequest(): array
    {
        return app(EditContext::class)->isEnabled() ? $this->draft() : $this->published();
    }

    /**
     * Write a whole document back to the draft columns. Pages missing from
     * the document are removed; settings keys missing from it are left
     * alone, so a partial document can never silently wipe the theme.
     *
     * @param  array<string, mixed>  $document
     */
    public function saveDraft(array $document): void
    {
        $siteId = $this->siteContext->id();

        if ($siteId === null) {
            return;
        }

        DB::transaction(function () use ($document, $siteId): void {
            $this->writePages($siteId, is_array($document[self::PAGES_KEY] ?? null) ? $document[self::PAGES_KEY] : []);

            foreach ($document as $key => $value) {
                if ($key === self::PAGES_KEY) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['site_id' => $siteId, 'key' => (string) $key],
                    ['draft' => $value],
                );
            }
        });
    }

    public function flushPublishedCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The document shipped with the application, used until the client has
     * published anything of her own.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return (array) config('site', []);
    }

    /**
     * @param  'draft'|'published'  $column
     * @return array<string, mixed>
     */
    private function assemble(string $column): array
    {
        $siteId = $this->siteContext->id();

        if ($siteId === null) {
            return [];
        }

        try {
            $settings = Setting::query()->where('site_id', $siteId)->get();
            $pages = Page::query()->where('site_id', $siteId)->orderBy('sort_order')->orderBy('id')->get();
        } catch (QueryException) {
            return [];
        }

        $document = [];

        foreach ($settings as $setting) {
            $value = $setting->{$column} ?? $setting->published;

            if ($value !== null) {
                $document[$setting->key] = $value;
            }
        }

        $assembled = [];

        foreach ($pages as $page) {
            /*
             * Unlike a settings key, a page's draft is authoritative: a null
             * draft means the client deleted the page, and it must disappear
             * from the draft document while the live site keeps it until she
             * publishes.
             */
            $data = $page->{$column};

            if (! is_array($data) || ! is_string($data['slug'] ?? null)) {
                continue;
            }

            $slug = $data['slug'];
            unset($data['slug']);

            $assembled[$slug] = $data;
        }

        if ($assembled !== []) {
            $document[self::PAGES_KEY] = $assembled;
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $pages
     */
    private function writePages(int $siteId, array $pages): void
    {
        $keptIds = [];
        $order = 0;

        foreach ($pages as $slug => $page) {
            if (! is_array($page)) {
                continue;
            }

            $row = Page::query()->firstOrNew(['site_id' => $siteId, 'slug' => (string) $slug]);

            $row->fill([
                'title' => (string) ($page['title'] ?? $slug),
                'type' => (string) ($page['type'] ?? 'content'),
                'status' => (string) ($page['status'] ?? Page::STATUS_PUBLISHED),
                'sort_order' => $order++,
                'draft' => ['slug' => (string) $slug, ...$page],
            ])->save();

            $keptIds[] = $row->getKey();
        }

        $this->removeMissingPages($siteId, $keptIds);
    }

    /**
     * A page dropped from the draft is not deleted outright: its published
     * copy has to keep serving the live site until the client publishes.
     * Only a page that was never live goes straight out.
     *
     * @param  list<int>  $keptIds
     */
    private function removeMissingPages(int $siteId, array $keptIds): void
    {
        $missing = Page::query()
            ->where('site_id', $siteId)
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->get();

        foreach ($missing as $page) {
            if ($page->published === null) {
                $page->delete();

                continue;
            }

            $page->forceFill(['draft' => null])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isEmptyDocument(array $document): bool
    {
        return $document === [] || ($document[self::PAGES_KEY] ?? []) === [];
    }
}
