<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Revision extends Model
{
    protected $table = 'gadyacms_revisions';

    /** @var list<string> */
    protected $fillable = ['site_id', 'snapshot', 'published_by', 'label', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'published_by');
    }
}
