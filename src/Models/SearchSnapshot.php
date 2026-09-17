<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class SearchSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'gadyacms_search_snapshots';

    /** @var list<string> */
    protected $fillable = ['site_id', 'kind', 'key', 'clicks', 'impressions', 'ctr', 'position', 'period_start', 'period_end', 'fetched_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
            'period_start' => 'date',
            'period_end' => 'date',
            'fetched_at' => 'datetime',
        ];
    }
}
