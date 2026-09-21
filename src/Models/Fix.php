<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing Gadya CMS put right: a photo it described, a page it wrote a
 * search snippet for. Kept so the client can see what changed and who
 * wrote it, and undo it if she would rather have her own words.
 */
class Fix extends Model
{
    protected $table = 'gadyacms_fixes';

    protected $fillable = ['audit', 'subject', 'before', 'after', 'written_by'];

    /** In plain English, for the client. */
    public function says(): string
    {
        return match ($this->audit) {
            'image-alt' => 'Described a photo so screen readers can read it out',
            'meta-description' => 'Wrote the sentence that shows under this page in search results',
            default => $this->audit,
        };
    }
}
