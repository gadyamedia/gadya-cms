<?php

namespace Gadya\Cms\Events;

use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An enquiry has arrived and is in the inbox - through a built form or a
 * configured one alike.
 *
 * For a site that wants to do something of its own with every enquiry
 * without writing a destination: listen for this. It is dispatched after
 * the enquiry is kept and after the form's destinations have run, and a
 * listener that throws is reported without reaching the visitor. Queue a
 * listener that talks to anything slow.
 */
class FormSubmitted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data  The answers, as kept
     */
    public function __construct(
        public readonly FormSubmission $submission,
        public readonly array $data,
        public readonly SubmissionContext $context,
        public readonly ?Form $form = null,
        public readonly ?FormDefinition $definition = null,
    ) {}

    /** The form's slug, whichever kind of form it is. */
    public function formName(): string
    {
        return (string) $this->submission->form;
    }
}
