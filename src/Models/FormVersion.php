<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A form's questions as they were at one save.
 */
class FormVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gadyacms_form_versions';

    /** @var list<string> */
    protected $fillable = ['form_id', 'version', 'fields', 'messages', 'created_by', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fields' => 'array', 'messages' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
