<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\ImageCapabilities;
use Gadya\Cms\Support\Images;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;
use Throwable;

class ProcessMediaUpload implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * @param  string  $sourceDisk  Name of the filesystem disk holding the already-persisted
     *                              upload, e.g. "local". Must NOT be a raw temp path: the worker
     *                              runs in a separate process from the request that staged the
     *                              file, and PHP deletes unmoved $_FILES temp files at request
     *                              shutdown.
     * @param  string  $sourcePath  Path to the staged file, relative to $sourceDisk.
     */
    public function __construct(
        public readonly Media $mediaItem,
        public readonly string $sourceDisk,
        public readonly string $sourcePath,
    ) {}

    public function handle(ImageCapabilities $capabilities): void
    {
        $maxEdge = (int) config('gadya-cms.media.max_edge', 2400);
        $thumbnailEdge = (int) config('gadya-cms.media.thumbnail_edge', 400);
        $directory = (string) config('gadya-cms.media.directory', 'site-media');

        $sourceDisk = Storage::disk($this->sourceDisk);

        if (! $sourceDisk->exists($this->sourcePath)) {
            throw new RuntimeException(
                "Staged upload file is missing from disk [{$this->sourceDisk}] at path [{$this->sourcePath}].",
            );
        }

        /** @var list<string> $writtenPaths */
        $writtenPaths = [];
        $disk = Storage::disk($this->mediaItem->disk);

        try {
            $sourceContents = $sourceDisk->get($this->sourcePath);

            $images = app(Images::class);
            $image = $images->read($sourceContents);
            $image->scaleDown(width: $maxEdge, height: $maxEdge);

            $base = Str::of($this->mediaItem->filename)->beforeLast('.')->toString();

            $webpPath = "{$directory}/{$base}.webp";
            $thumbnailPath = "{$directory}/thumbnails/{$base}.webp";

            $this->putOrFail($disk, $webpPath, $images->webp($image, (int) config('gadya-cms.media.quality', 82)));
            $writtenPaths[] = $webpPath;

            $thumbnail = $images->read($sourceContents)
                ->scaleDown(width: $thumbnailEdge, height: $thumbnailEdge);
            $this->putOrFail($disk, $thumbnailPath, $images->webp($thumbnail, (int) config('gadya-cms.media.thumbnail_quality', 78)));
            $writtenPaths[] = $thumbnailPath;

            /*
             * Responsive variants, narrower than the photo only: a width
             * the photo cannot fill would just be the original again.
             */
            $variants = [];

            foreach ((array) config('gadya-cms.media.variants', []) as $width) {
                $width = (int) $width;

                if ($width <= 0 || $width >= $image->width()) {
                    continue;
                }

                $variantPath = "{$directory}/variants/{$base}-{$width}.webp";
                $variant = $images->read($sourceContents)->scaleDown(width: $width);
                $this->putOrFail($disk, $variantPath, $images->webp($variant, (int) config('gadya-cms.media.quality', 82)));
                $writtenPaths[] = $variantPath;
                $variants[(string) $width] = $variantPath;
            }

            if ($capabilities->missingBinaries() === []) {
                foreach ($writtenPaths as $path) {
                    ImageOptimizer::optimize($disk->path($path));
                }
            }

            $this->mediaItem->update([
                'path' => $webpPath,
                'thumbnail_path' => $thumbnailPath,
                'variants' => $variants,
                'mime_type' => 'image/webp',
                'width' => $image->width(),
                'height' => $image->height(),
                'size' => $disk->size($webpPath),
                'status' => Media::STATUS_READY,
            ]);
        } catch (Throwable $exception) {
            $this->removeWrittenPaths($disk, $writtenPaths);

            throw $exception;
        }

        $this->deleteSourceQuietly($sourceDisk);
    }

    /**
     * Laravel only invokes this once the queue worker has exhausted every
     * configured attempt (see Worker::markJobAsFailedIfWillExceedMaxAttempts,
     * which calls Job::fail() -> failed() only when attempts >= tries), so
     * this is the correct, and only, place to perform terminal cleanup of a
     * staged upload that will never be retried again.
     */
    public function failed(?Throwable $exception): void
    {
        Storage::disk($this->sourceDisk)->delete($this->sourcePath);

        $this->mediaItem->update(['status' => Media::STATUS_FAILED]);

        Log::error('Media upload processing failed', [
            'media_id' => $this->mediaItem->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function removeWrittenPaths(Filesystem $disk, array $paths): void
    {
        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }

    /**
     * Laravel's filesystem disks default to "throw" => false, so a genuine
     * write failure (disk full, permission denied) from Filesystem::put()
     * only returns false rather than throwing. Without this guard such a
     * failure would pass silently and the MediaItem would be marked "ready"
     * with a derivative that was never actually written.
     */
    private function putOrFail(Filesystem $disk, string $path, string $contents): void
    {
        if (! $disk->put($path, $contents)) {
            throw new RuntimeException("Failed to write processed media to disk at path [{$path}].");
        }
    }

    /**
     * Removes the staged source after the MediaItem has already been committed
     * as "ready", without ever letting a delete failure propagate. By this
     * point the derivatives are good and the item is genuinely ready, so a
     * failure to remove the now-redundant source must not be treated as a job
     * failure: doing so would route into the catch block above and delete the
     * derivatives that were just successfully written and are already
     * referenced by the committed row. Instead, a failed delete is logged at
     * "warning" so it is actually noticed: the source is an unstripped,
     * GPS-bearing original, and leaving it on disk is exactly the privacy
     * problem this pipeline exists to close, even though it no longer affects
     * the MediaItem's correctness.
     */
    private function deleteSourceQuietly(Filesystem $sourceDisk): void
    {
        try {
            $deleted = $sourceDisk->delete($this->sourcePath);
        } catch (Throwable $exception) {
            $this->logUndeletedSource($exception->getMessage());

            return;
        }

        if (! $deleted) {
            $this->logUndeletedSource('Filesystem::delete() returned false.');
        }
    }

    private function logUndeletedSource(string $reason): void
    {
        Log::warning('Staged media upload could not be deleted after successful processing; an unstripped, GPS-bearing original may still be on disk.', [
            'media_id' => $this->mediaItem->id,
            'source_disk' => $this->sourceDisk,
            'source_path' => $this->sourcePath,
            'reason' => $reason,
        ]);
    }
}
