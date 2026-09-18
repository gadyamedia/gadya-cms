<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address that leads nowhere: asked for by a visitor, or linked from
 * the site's own pages.
 */
class BrokenLink extends Model
{
    public const VISITED = 'visited';

    public const LINKED = 'linked';

    protected $table = 'gadyacms_broken_links';

    /** @var list<string> */
    protected $fillable = ['site_id', 'signature', 'url', 'kind', 'found_on', 'referrer_host', 'status_code', 'hits', 'last_seen_at', 'resolved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'status_code' => 'integer',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
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
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Note that an address failed. The same address seen again is counted
     * rather than listed twice, and one that had been marked fixed comes
     * back - because it evidently is not.
     */
    public static function note(string $url, string $kind = self::VISITED, ?string $foundOn = null, ?string $referrer = null, ?int $status = null): self
    {
        $signature = sha1($kind.'|'.$url.'|'.($foundOn ?? ''));

        $link = static::query()->firstOrNew([
            'site_id' => app(SiteContext::class)->id(),
            'signature' => $signature,
        ]);

        $link->fill([
            'url' => $url,
            'kind' => $kind,
            'found_on' => $foundOn,
            'referrer_host' => $referrer ?? $link->referrer_host,
            'status_code' => $status ?? $link->status_code,
            'hits' => $link->exists ? $link->hits + 1 : 1,
            'last_seen_at' => now(),
            'resolved_at' => null,
        ])->save();

        return $link;
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            self::VISITED => 'Someone went there',
            self::LINKED => 'Linked from the site',
        ];
    }
}
