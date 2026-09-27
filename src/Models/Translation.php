<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The translated words of one piece of content in one language: a page
 * (`pages.about`), a top-level settings key (`nav`, `announcement`), or an
 * article, event or term (`post:12`). Only words are kept - the structure,
 * the photos and the addresses always come from the default language.
 *
 * Like the rest of the site it has a draft and a published copy. A
 * machine translation lands in the draft marked for review, and is held
 * back from publishing until a person has read it.
 */
class Translation extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MACHINE = 'machine';

    protected $table = 'gadyacms_translations';

    /** @var list<string> */
    protected $fillable = [
        'site_id', 'locale', 'key', 'draft', 'published', 'source', 'needs_review',
        'source_hash', 'translated_at', 'reviewed_at', 'reviewed_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
        'needs_review' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
            'needs_review' => 'boolean',
            'translated_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isMachineTranslated(): bool
    {
        return $this->source === self::SOURCE_MACHINE;
    }
}
