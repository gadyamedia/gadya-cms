<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the client asked for in the Gadya Media portal, drafted here
 * and waiting on a person. `portal_id` is the request's number in the
 * portal, which is how the portal knows which of its requests this is.
 *
 * @property int $portal_id
 * @property string|null $page_slug
 * @property string|null $page_title
 * @property string $instructions
 * @property string|null $requested_by
 * @property string $status
 * @property list<array{field: string, before: string|null, after: string}>|null $changes
 * @property string|null $preview_url
 */
class ChangeRequest extends Model
{
    /** In the draft, not yet live. */
    public const STATUS_DRAFTED = 'drafted';

    /** Went live with a publish. */
    public const STATUS_PUBLISHED = 'published';

    /** Thrown away, or undone before a publish. */
    public const STATUS_DISCARDED = 'discarded';

    protected $table = 'gadyacms_change_requests';

    /** @var list<string> */
    protected $fillable = ['site_id', 'portal_id', 'page_id', 'page_slug', 'page_title', 'instructions', 'requested_by', 'status', 'changes', 'preview_url'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portal_id' => 'integer',
            'changes' => 'array',
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
    public function scopeDrafted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFTED);
    }

    public function isDrafted(): bool
    {
        return $this->status === self::STATUS_DRAFTED;
    }
}
