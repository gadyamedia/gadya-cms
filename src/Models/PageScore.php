<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class PageScore extends Model
{
    public $timestamps = false;

    protected $table = 'gadyacms_page_scores';

    /** @var list<string> */
    protected $fillable = ['site_id', 'path', 'strategy', 'performance', 'accessibility', 'best_practices', 'seo', 'lcp_ms', 'cls', 'opportunities', 'failures', 'checked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performance' => 'integer',
            'accessibility' => 'integer',
            'best_practices' => 'integer',
            'seo' => 'integer',
            'lcp_ms' => 'integer',
            'cls' => 'float',
            'opportunities' => 'array',
            'failures' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
