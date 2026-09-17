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
