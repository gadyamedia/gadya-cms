<?php

namespace Gadya\Cms\Transfer;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Option;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Models\Setting;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * A whole site in one file, to move it between machines: every page and
 * setting with both draft and published copies, the articles, the
 * redirects, the options, and - when asked - the photos themselves.
 *
 * Secrets are never exported. An API key belongs to one install.
 */
class SiteExporter
{
    public const FORMAT = 1;

    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $siteId = $this->siteContext->id();

        return [
            'format' => self::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'site' => ['key' => $this->siteContext->get()?->key, 'name' => $this->siteContext->get()?->name],
            'pages' => Page::query()->where('site_id', $siteId)->orderBy('sort_order')->get()
                ->map(fn (Page $page): array => $page->only(['slug', 'title', 'type', 'status', 'sort_order', 'draft', 'published']))->all(),
            'settings' => Setting::query()->where('site_id', $siteId)->get()
                ->map(fn (Setting $setting): array => $setting->only(['key', 'draft', 'published']))->all(),
            'posts' => Post::query()->where('site_id', $siteId)->get()
                ->map(fn (Post $post): array => [
                    ...$post->only(['title', 'slug', 'excerpt', 'content', 'image', 'hero_alt', 'meta_title', 'meta_description', 'faq', 'reading_time', 'target_keyword', 'target_location', 'search_intent', 'source', 'ai_meta', 'status']),
                    'published_at' => $post->published_at?->toIso8601String(),
                ])->all(),
            'redirects' => Redirect::query()->where('site_id', $siteId)->get()
                ->map(fn (Redirect $redirect): array => $redirect->only(['from_path', 'to_path', 'status_code']))->all(),
            'options' => Option::query()->where('site_id', $siteId)->get()
                ->filter(fn (Option $option): bool => ! isset($option->value['value']['encrypted']))
                ->map(fn (Option $option): array => ['key' => $option->key, 'value' => $option->value])->values()->all(),
            'media' => Media::query()->where('site_id', $siteId)->get()
                ->map(fn (Media $media): array => $media->only(['filename', 'original_name', 'disk', 'path', 'thumbnail_path', 'variants', 'mime_type', 'width', 'height', 'size', 'alt_text', 'folder', 'tags', 'is_legacy', 'status']))->all(),
        ];
    }

    /**
     * Write the export to a zip (`.zip`) or a bare JSON file (anything
     * else). Only a zip can carry the photos.
     */
    public function write(string $path, bool $withMedia = false): string
    {
        $data = $this->toArray();
        File::ensureDirectoryExists(dirname($path));

        if (! str_ends_with(strtolower($path), '.zip')) {
            if ($withMedia) {
                throw new RuntimeException('Photos can only be included in a .zip export.');
            }

            File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $path;
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is not installed; export to a .json file instead.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not write {$path}.");
        }

        $zip->addFromString('site.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($withMedia) {
            foreach ($data['media'] as $media) {
                if ($media['is_legacy']) {
                    continue;
                }

                $disk = Storage::disk($media['disk']);

                foreach (array_filter([$media['path'], $media['thumbnail_path'], ...array_values((array) ($media['variants'] ?? []))]) as $file) {
                    if ($disk->exists($file)) {
                        $zip->addFromString('media/'.$file, (string) $disk->get($file));
                    }
                }
            }
        }

        $zip->close();

        return $path;
    }
}
