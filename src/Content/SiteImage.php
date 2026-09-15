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
    public function url(string $reference): string
    {
        $item = $this->library()->get($reference);

        if ($item === null || $item->is_legacy) {
            return asset(config('gadya-cms.media.legacy_directory', 'images/site').'/'.$reference);
        }

        return Storage::disk($item->disk)->url($item->path);
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
