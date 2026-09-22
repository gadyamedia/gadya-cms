<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Something a visitor sent through a form on the site. Kept here as well
 * as emailed, because an email can be lost, filtered or deleted, and the
 * enquiry from a fortnight ago is the one the client suddenly needs.
 */
class FormSubmission extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_NEW = 'new';

    public const STATUS_READ = 'read';

    /** Someone has replied to it. */
    public const STATUS_ANSWERED = 'answered';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'gadyacms_form_submissions';

    /** @var list<string> */
    protected $fillable = ['site_id', 'form', 'data', 'path', 'referrer_host', 'country', 'status', 'read_at', 'created_at', 'notes', 'follow_up_at', 'answered_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_NEW,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'answered_at' => 'datetime',
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
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NEW);
    }

    /**
     * Enquiries someone said they would come back to, and the day has
     * come - excluding the ones put away since.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDueForFollowUp(Builder $query): Builder
    {
        return $query
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            ->where('status', '!=', self::STATUS_ARCHIVED);
    }

    /**
     * The best one-line handle on who sent it: a name if the form asked
     * for one, else an email, else the first thing they typed.
     */
    public function sender(): string
    {
        $data = $this->data ?? [];

        foreach (['name', 'full_name', 'first_name', 'email'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        $first = collect($data)->first(fn ($value): bool => is_string($value) && $value !== '');

        return is_string($first) ? Str::limit($first, 40) : 'Someone';
    }

    public function markRead(): void
    {
        if ($this->status === self::STATUS_NEW) {
            $this->update(['status' => self::STATUS_READ, 'read_at' => now()]);
        }
    }

    /**
     * Someone has replied. Kept as a date, so "how long did we take?" has
     * an answer; opening it on the way is implied.
     */
    public function markAnswered(): void
    {
        $this->update([
            'status' => self::STATUS_ANSWERED,
            'answered_at' => $this->answered_at ?? now(),
            'read_at' => $this->read_at ?? now(),
        ]);
    }
}
