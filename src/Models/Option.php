<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of site configuration that is not content - see the Options
 * service for what lives here and why.
 */
class Option extends Model
{
    protected $table = 'gadyacms_options';

    /** @var list<string> */
    protected $fillable = ['site_id', 'key', 'value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
