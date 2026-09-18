<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something happening on a date. An event is over when it ends, not when
 * it starts, so a three-day camp stays on the "what's on" list through
 * to its last afternoon.
 */
class Event extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'gadyacms_events';

    /** @var list<string> */
    protected $fillable = [
        'site_id', 'title', 'slug', 'summary', 'body', 'image', 'hero_alt',
        'starts_at', 'ends_at', 'all_day', 'location', 'price', 'booking_url', 'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'all_day' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
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
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->published()
            ->where(fn (Builder $inner) => $inner->where('ends_at', '>=', now())->orWhere(fn (Builder $noEnd) => $noEnd->whereNull('ends_at')->where('starts_at', '>=', now()->startOfDay())))
            ->orderBy('starts_at');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePast(Builder $query): Builder
    {
        return $query->published()
            ->where(fn (Builder $inner) => $inner->where('ends_at', '<', now())->orWhere(fn (Builder $noEnd) => $noEnd->whereNull('ends_at')->where('starts_at', '<', now()->startOfDay())))
            ->orderByDesc('starts_at');
    }

    public function hasFinished(): bool
    {
        return ($this->ends_at ?? $this->starts_at->endOfDay())->isPast();
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function publicPath(): string
    {
        return '/'.trim((string) config('gadya-cms.events.prefix', 'events'), '/').'/'.$this->slug;
    }

    /**
     * The dates in the words a person would use: one day, a range, or a
     * time on a day.
     */
    public function when(): string
    {
        $start = $this->starts_at;
        $end = $this->ends_at;

        if ($this->all_day) {
            return $end === null || $end->isSameDay($start)
                ? $start->format('l j F Y')
                : $start->format('j F').' to '.$end->format('j F Y');
        }

        if ($end === null) {
            return $start->format('l j F Y, g:ia');
        }

        return $end->isSameDay($start)
            ? $start->format('l j F Y, g:ia').' to '.$end->format('g:ia')
            : $start->format('j F, g:ia').' to '.$end->format('j F Y, g:ia');
    }
}
