<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Quality\PhotoDescriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Describes a handful of photos in the background, so asking for fifty
 * never ties up the screen. Photos someone has described since, or marked
 * as decoration, are left alone; a photo still being processed after its
 * upload is waited for.
 */
class WriteMissingAltText implements ShouldQueue
{
    use Queueable;

    /** How many photos one job looks at. */
    public const CHUNK = 10;

    public int $tries = 5;

    public int $timeout = 600;

    /**
     * @param  list<int>  $mediaIds
     */
    public function __construct(public readonly array $mediaIds) {}

    public function handle(PhotoDescriber $describer): void
    {
        if (! $describer->available()) {
            return;
        }

        $waiting = false;

        foreach (Media::query()->whereKey($this->mediaIds)->missingAltText()->get() as $photo) {
            if ($photo->status === Media::STATUS_PROCESSING) {
                $waiting = true;

                continue;
            }

            if (! $photo->isReady()) {
                continue;
            }

            if (! $describer->describe($photo)) {
                return;
            }
        }

        if ($waiting && $this->attempts() < $this->tries) {
            $this->release(60);
        }
    }

    /**
     * One job per handful, so a slow model never times out a long list.
     *
     * @param  iterable<int>  $mediaIds
     */
    public static function dispatchFor(iterable $mediaIds, int $delaySeconds = 0): int
    {
        $ids = collect($mediaIds)->map(fn ($id): int => (int) $id)->unique()->values();

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            static::dispatch($chunk->values()->all())->delay($delaySeconds > 0 ? now()->addSeconds($delaySeconds) : null);
        }

        return $ids->count();
    }
}
