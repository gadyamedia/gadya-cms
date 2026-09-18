<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A reader's reply. Nothing a stranger writes appears on the site until
 * someone who works on it says so.
 */
class Comment extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const SPAM = 'spam';

    protected $table = 'gadyacms_comments';

    /** @var list<string> */
    protected $fillable = ['site_id', 'post_id', 'author_name', 'author_email', 'body', 'status', 'visitor_hash', 'approved_by', 'approved_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::PENDING,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
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
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function approve(?int $by = null): void
    {
        $this->update(['status' => self::APPROVED, 'approved_by' => $by, 'approved_at' => now()]);
    }

    public function markSpam(): void
    {
        $this->update(['status' => self::SPAM, 'approved_at' => null]);
    }

    public function excerpt(int $length = 80): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $this->body) ?? ''), $length);
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::PENDING => 'Waiting',
            self::APPROVED => 'Showing',
            self::SPAM => 'Spam',
        ];
    }
}
