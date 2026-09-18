<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    public const STATUS_READY = 'ready';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    protected $table = 'gadyacms_media';

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'filename',
        'original_name',
        'disk',
        'path',
        'thumbnail_path',
        'variants',
        'focal_x',
        'focal_y',
        'mime_type',
        'width',
        'height',
        'size',
        'alt_text',
        'folder',
        'tags',
        'uploaded_by',
        'is_legacy',
        'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'disk' => 'public',
        'is_legacy' => false,
        'status' => self::STATUS_READY,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_legacy' => 'boolean',
            'tags' => 'array',
            'focal_x' => 'integer',
            'focal_y' => 'integer',
            'variants' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInFolder(Builder $query, ?string $folder): Builder
    {
        return $folder === null || $folder === '' ? $query : $query->where('folder', $folder);
    }

    /**
     * Every folder in use, for a select. Folders are just a label on the
     * row: nothing moves on disk when one is renamed.
     *
     * @return list<string>
     */
    public static function folders(): array
    {
        return static::query()->whereNotNull('folder')->where('folder', '!=', '')->distinct()->orderBy('folder')->pluck('folder')->all();
    }

    /**
     * Where to keep the photo centred when a template crops it - the part
     * a person would have framed. Answers the CSS value directly, because
     * that is the only thing anyone does with it.
     */
    public function focalPosition(): string
    {
        return ($this->focal_x ?? 50).'% '.($this->focal_y ?? 50).'%';
    }

    /**
     * The nine places a client actually means when she says the crop is
     * wrong, as CSS percentages.
     *
     * @return array<string, array{label: string, x: int, y: int}>
     */
    public static function focusPresets(): array
    {
        return [
            'top-left' => ['label' => 'Top left', 'x' => 20, 'y' => 20],
            'top' => ['label' => 'Top', 'x' => 50, 'y' => 15],
            'top-right' => ['label' => 'Top right', 'x' => 80, 'y' => 20],
            'left' => ['label' => 'Left', 'x' => 15, 'y' => 50],
            'centre' => ['label' => 'Middle (the usual)', 'x' => 50, 'y' => 50],
            'right' => ['label' => 'Right', 'x' => 85, 'y' => 50],
            'bottom-left' => ['label' => 'Bottom left', 'x' => 20, 'y' => 80],
            'bottom' => ['label' => 'Bottom', 'x' => 50, 'y' => 85],
            'bottom-right' => ['label' => 'Bottom right', 'x' => 80, 'y' => 80],
        ];
    }

    public function focusPreset(): string
    {
        $closest = 'centre';
        $distance = PHP_INT_MAX;

        foreach (static::focusPresets() as $key => $preset) {
            $to = abs($preset['x'] - ($this->focal_x ?? 50)) + abs($preset['y'] - ($this->focal_y ?? 50));

            if ($to < $distance) {
                $distance = $to;
                $closest = $key;
            }
        }

        return $closest;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    protected static function newFactory(): Factory
    {
        return MediaFactory::new();
    }
}
