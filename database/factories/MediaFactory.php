<?php

namespace Gadya\Cms\Database\Factories;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->unique()->slug(2).'.webp';
        $directory = (string) config('gadya-cms.media.directory', 'site-media');

        return [
            'site_id' => fn (): ?int => app(SiteContext::class)->id(),
            'filename' => $filename,
            'original_name' => $filename,
            'disk' => 'public',
            'path' => "{$directory}/{$filename}",
            'thumbnail_path' => "{$directory}/thumbnails/{$filename}",
            'mime_type' => 'image/webp',
            'width' => 1200,
            'height' => 900,
            'size' => 120000,
            'is_legacy' => false,
            'status' => Media::STATUS_READY,
        ];
    }

    public function legacy(): self
    {
        return $this->state(fn (): array => [
            'is_legacy' => true,
            'thumbnail_path' => null,
            'path' => config('gadya-cms.media.legacy_directory', 'images/site').'/'.fake()->unique()->slug(2).'.jpg',
        ]);
    }

    public function processing(): self
    {
        return $this->state(fn (): array => ['status' => Media::STATUS_PROCESSING, 'path' => '']);
    }
}
