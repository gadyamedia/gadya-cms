<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PageView extends Model
{
    public $timestamps = false;

    protected $table = 'gadyacms_page_views';

    /** @var list<string> */
    protected $fillable = [
        'site_id', 'path', 'route_name', 'visitor_hash', 'referrer_host',
        'utm_source', 'utm_medium', 'utm_campaign', 'device_category',
        'country', 'region', 'city', 'viewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where('viewed_at', '>=', $since);
    }
}
