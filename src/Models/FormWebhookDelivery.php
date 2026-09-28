<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One enquiry handed to one webhook: how many tries it took, and what the
 * other end said - so "Zapier never got it" can be answered with a date
 * and a status code rather than a shrug.
 */
class FormWebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $table = 'gadyacms_form_webhook_deliveries';

    /** @var list<string> */
    protected $fillable = ['form_id', 'submission_id', 'url', 'status', 'attempts', 'response_status', 'response_body', 'error', 'delivered_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING, 'attempts' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'attempts' => 'integer', 'response_status' => 'integer'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }
}
