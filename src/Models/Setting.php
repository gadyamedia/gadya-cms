<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single top-level key of the site document that is not a page: the theme,
 * the navigation, the slug redirect table, the global contact details.
 */
class Setting extends Model
{
    protected $table = 'gadyacms_settings';

    /** @var list<string> */
    protected $fillable = ['site_id', 'key', 'draft', 'published'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
