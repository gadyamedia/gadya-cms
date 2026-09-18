<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Someone who asked to hear from the site. Kept here rather than pushed
 * straight to a mailing service, so the list belongs to the client and
 * can be exported to whichever service she ends up using.
 */
class Subscriber extends Model
{
    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    protected $table = 'gadyacms_subscribers';

    /** @var list<string> */
    protected $fillable = ['site_id', 'email', 'name', 'status', 'source', 'unsubscribed_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::SUBSCRIBED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
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
    public function scopeSubscribed(Builder $query): Builder
    {
        return $query->where('status', self::SUBSCRIBED);
    }

    public function isSubscribed(): bool
    {
        return $this->status === self::SUBSCRIBED;
    }

    /**
     * A link that takes someone off the list without an account and
     * without a form: signed, never expiring, because an unsubscribe link
     * in an old email must still work.
     */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('gadya-cms.newsletter.unsubscribe', ['subscriber' => $this->getKey()]);
    }

    public function unsubscribe(): void
    {
        $this->update(['status' => self::UNSUBSCRIBED, 'unsubscribed_at' => now()]);
    }
}
