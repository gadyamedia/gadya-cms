<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A half-filled form someone asked to finish later. Only a hash of the
 * token is kept, so the link in their email is the only way back in, and
 * it stops working when it expires or once the form is sent.
 */
class FormDraft extends Model
{
    protected $table = 'gadyacms_form_drafts';

    /** @var list<string> */
    protected $fillable = ['form_id', 'token_hash', 'email', 'data', 'step', 'path', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'expires_at' => 'datetime', 'step' => 'integer'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
