<?php

namespace Gadya\Cms\Observers;

use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Portal\SubmissionPush;

/**
 * Opening an enquiry, or marking it answered, is news to the portal:
 * whichever screen did it, the dates on the row are what changed.
 */
class ReportSubmissionStatus
{
    public function updated(FormSubmission $submission): void
    {
        if ($submission->wasChanged(['read_at', 'answered_at'])) {
            app(SubmissionPush::class)->queueStatus($submission);
        }
    }
}
