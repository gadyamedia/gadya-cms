<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Indexes the images that shipped with the site so they show up in the
 * photo library alongside the ones the client uploads. They are marked
 * legacy: they keep being served from their original location and are never
 * deleted by the CMS.
 */
class ImportLegacyMediaCommand extends Command
{
    protected $signature = 'gadya-cms:import-legacy-media';

    protected $description = 'Index the images already shipped with the site into the photo library';

    public function handle(SiteContext $siteContext): int
    {
        $relative = (string) config('gadya-cms.media.legacy_directory', 'images/site');
        $directory = public_path($relative);

        if (! File::isDirectory($directory)) {
            $this->warn("No legacy media directory found at [{$directory}]; nothing to import.");

            return self::SUCCESS;
        }

        $imported = 0;

        foreach (File::files($directory) as $file) {
            $media = Media::query()->firstOrCreate(
                ['filename' => $file->getFilename()],
                [
                    'site_id' => $siteContext->id(),
                    'original_name' => $file->getFilename(),
                    'disk' => 'public',
                    'path' => $relative.'/'.$file->getFilename(),
                    'mime_type' => File::mimeType($file->getPathname()),
                    'size' => $file->getSize(),
                    'is_legacy' => true,
                    'status' => Media::STATUS_READY,
                ],
            );

            if ($media->wasRecentlyCreated) {
                $imported++;
            }
        }

        $this->info("Indexed {$imported} legacy images.");

        return self::SUCCESS;
    }
}
