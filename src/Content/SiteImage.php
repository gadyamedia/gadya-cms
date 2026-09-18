<?php

namespace Gadya\Cms\Content;

use Gadya\Cms\Models\Media;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the filename stored in the document to a URL. Images uploaded
 * through the CMS live on a disk; images that shipped with the site are
 * still served straight from the public directory.
 */
class SiteImage
{
    /**
     * The photo's URL - or, given a width, the smallest variant at least
     * that wide, so a card never loads the full-size original.
     */
    public function url(string $reference, ?int $width = null): string
    {
        $item = $this->library()->get($reference);

        if ($item === null || $item->is_legacy) {
            return asset(config('gadya-cms.media.legacy_directory', 'images/site').'/'.$reference);
        }

        if ($width !== null) {
            foreach ($this->variantsOf($item) as $variantWidth => $path) {
                if ($variantWidth >= $width) {
                    return Storage::disk($item->disk)->url($path);
                }
            }
        }

        return Storage::disk($item->disk)->url($item->path);
    }

    /**
     * Where a cropped photo should stay centred, as a CSS declaration:
     *
     *     <img style="@siteFocus($page['hero_image'])" ...>
     *
     * A photo nobody has framed answers with the middle, which is what a
     * browser would have done anyway.
     */
    public function focus(string $reference): string
    {
        $item = $this->library()->get($reference);

        return 'object-position: '.($item?->focalPosition() ?? '50% 50%').';';
    }

    /**
     * A `srcset` attribute value: every variant plus the original, each
     * with its width, so the browser picks the one its layout needs.
     * A photo with no variants answers with the original alone, which is
     * still valid.
     */
    public function srcset(string $reference): string
    {
        $item = $this->library()->get($reference);

        if ($item === null || $item->is_legacy) {
            return $this->url($reference);
        }

        $candidates = [];

        foreach ($this->variantsOf($item) as $width => $path) {
            $candidates[] = Storage::disk($item->disk)->url($path).' '.$width.'w';
        }

        $candidates[] = Storage::disk($item->disk)->url($item->path).($item->width ? ' '.$item->width.'w' : '');

        return implode(', ', $candidates);
    }

    /**
     * @return array<int, string> width => path, narrowest first
     */
    private function variantsOf(Media $item): array
    {
        $variants = [];

        foreach ((array) ($item->variants ?? []) as $width => $path) {
            if (is_string($path) && (int) $width > 0) {
                $variants[(int) $width] = $path;
            }
        }

        ksort($variants);

        return $variants;
    }

    public function thumbnailUrl(string $reference): string
    {
        $item = $this->library()->get($reference);

        if ($item === null || $item->is_legacy || $item->thumbnail_path === null) {
            return $this->url($reference);
        }

        return Storage::disk($item->disk)->url($item->thumbnail_path);
    }

    /**
     * @return Collection<string, Media>
     */
    private function library(): Collection
    {
        return once(function (): Collection {
            try {
                return Media::query()->get()->keyBy('filename');
            } catch (QueryException) {
                return new Collection;
            }
        });
    }
}
