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
        'mime_type',
        'width',
        'height',
        'size',
        'alt_text',
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
