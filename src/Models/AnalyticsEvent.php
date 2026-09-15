<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'gadyacms_analytics_events';

    /** @var list<string> */
    protected $fillable = ['site_id', 'name', 'path', 'visitor_hash', 'metadata', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where('created_at', '>=', $since);
    }
}
