<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Portal\SubmissionPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the portal an enquiry has been opened or answered here, so it
 * stops chasing one somebody has already dealt with.
 */
class PushSubmissionStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $deleteWhenMissingModels = true;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly FormSubmission $submission) {}

    public function handle(SubmissionPush $push): void
    {
        $submission = $this->submission->fresh();

        if ($submission === null) {
            return;
        }

        $push->pushStatus($submission);
    }
}
