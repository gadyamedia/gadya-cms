<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Writes the responsive variants for photos uploaded before variants
 * existed, or after the configured widths changed. Legacy photos are
 * left alone: they are served from wherever they were shipped.
 */
class MakeMediaVariantsCommand extends Command
{
    protected $signature = 'gadya-cms:media-variants {--force : Rewrite variants that already exist}';

    protected $description = 'Generate responsive variants for photos that have none';

    public function handle(ImageCapabilities $capabilities): int
    {
        $manager = new ImageManager($capabilities->driver(), strip: true);
        $widths = array_map('intval', (array) config('gadya-cms.media.variants', []));
        $directory = (string) config('gadya-cms.media.directory', 'site-media');
        $done = 0;

        $query = Media::query()->ready()->where('is_legacy', false);

        if (! $this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('variants')->orWhere('variants', '[]')->orWhere('variants', '{}'));
        }

        foreach ($query->cursor() as $item) {
            $disk = Storage::disk($item->disk);

            if (! $disk->exists($item->path)) {
                $this->components->warn("{$item->original_name}: file missing, skipped.");

                continue;
            }

            try {
                $contents = (string) $disk->get($item->path);
                $image = $manager->read($contents);
                $base = Str::of($item->filename)->beforeLast('.')->toString();
                $variants = [];

                foreach ($widths as $width) {
                    if ($width <= 0 || $width >= $image->width()) {
                        continue;
                    }

                    $path = "{$directory}/variants/{$base}-{$width}.webp";
                    $disk->put($path, (string) $manager->read($contents)->scaleDown(width: $width)->toWebp((int) config('gadya-cms.media.quality', 82)));
                    $variants[(string) $width] = $path;
                }

                $item->update(['variants' => $variants, 'width' => $item->width ?: $image->width(), 'height' => $item->height ?: $image->height()]);
                $done++;
            } catch (Throwable $exception) {
                $this->components->error("{$item->original_name}: ".$exception->getMessage());
            }
        }

        $this->components->info("Wrote variants for {$done} photos.");

        return self::SUCCESS;
    }
}
