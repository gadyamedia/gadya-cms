<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Portal\SubmissionPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one enquiry to the Gadya Media portal. Tries a few times over an
 * hour or so; after that the five-minute sweep keeps trying for a week,
 * so a portal that is down for a morning loses nothing.
 */
class PushFormSubmission implements ShouldQueue
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

        if ($submission === null || $submission->pushed_at !== null) {
            return;
        }

        $push->push($submission);
    }
}
