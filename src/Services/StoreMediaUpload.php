<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Jobs\ProcessMediaUpload;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stages an upload on a disk the queue worker can reach and records a
 * placeholder row, so the browser never waits on image processing.
 */
class StoreMediaUpload
{
    public function __construct(private readonly SiteContext $siteContext) {}

    public function handle(UploadedFile $upload, ?int $uploadedBy = null, ?string $folder = null): Media
    {
        $stagingDisk = (string) config('gadya-cms.media.staging_disk', 'local');

        $media = Media::query()->create([
            'site_id' => $this->siteContext->id(),
            'filename' => Str::random(32).'.webp',
            'original_name' => $upload->getClientOriginalName(),
            'disk' => (string) config('gadya-cms.media.disk', 'public'),
            'path' => '',
            'uploaded_by' => $uploadedBy,
            'folder' => $folder !== null && trim($folder) !== '' ? trim($folder) : null,
            'status' => Media::STATUS_PROCESSING,
        ]);

        ProcessMediaUpload::dispatch($media, $stagingDisk, $upload->store('media-staging', $stagingDisk));

        return $media;
    }
}
