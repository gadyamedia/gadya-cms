<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a visitor did with a form: saw it, started it, reached a
 * step, or sent it. Counted the way the rest of the CMS's analytics are -
 * a daily-rotating identifier, never an address - so a form's figures are
 * as private as the dashboard's.
 */
class FormEvent extends Model
{
    public const VIEW = 'view';

    public const START = 'start';

    public const STEP = 'step';

    public const COMPLETE = 'complete';

    public $timestamps = false;

    protected $table = 'gadyacms_form_events';

    /** @var list<string> */
    protected $fillable = ['site_id', 'form_id', 'name', 'step', 'visitor_hash', 'path', 'referrer_host', 'duration_seconds', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'step' => 'integer', 'duration_seconds' => 'integer'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
