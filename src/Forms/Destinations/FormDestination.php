<?php

namespace Gadya\Cms\Forms\Destinations;

use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;

/**
 * Somewhere else an enquiry is handed on to once it is in the inbox: the
 * site's own leads table, a CRM, a booking system.
 *
 * A site whose own controller did more than store and email an enquiry
 * keeps doing it through a destination, so its form can still be handed
 * to the client. Most need no PHP - `forms.builder.destinations` in config
 * describes a model to create (see EloquentDestination). For anything a
 * mapping cannot say, a site writes a class of its own and names it there.
 *
 * `handle()` runs straight after the enquiry is kept, before anyone is
 * emailed. If it throws, the failure is reported and noted on the
 * enquiry, and the visitor is thanked all the same.
 */
interface FormDestination
{
    /** The name it is chosen by, and recorded under on each enquiry. */
    public function key(): string;

    /** What the client sees under "Also save to". */
    public function label(): string;

    /**
     * The attributes it can be given, with a few words on each, so the
     * panel can offer a mapping for them. Empty for a destination that
     * decides for itself what to take from the enquiry.
     *
     * @return array<string, string>
     */
    public function fields(): array;

    /**
     * Hand the enquiry on. Whatever it returns - the id of the record it
     * made, or a line of text - is noted on the enquiry and shown in the
     * inbox.
     *
     * The form's own mapping for this destination, when the client set one,
     * is `$form->setting('destination_maps.'.$this->key())`.
     *
     * @param  array<string, mixed>  $data  The enquiry's answers, as kept
     */
    public function handle(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): mixed;
}
