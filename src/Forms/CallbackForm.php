<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The "Speak with our team" form behind <x-gadya-cms::call-back />.
 *
 * Built in rather than configured, so the button works on any site
 * without a developer adding a form; a site that lists its own
 * `callback` under `forms.forms` replaces it. The one thing it keeps that
 * a contact form does not is the visitor's consent to be rung - the
 * exact words she agreed to, when and from where - because the portal
 * may pass the call to an AI receptionist, and a call nobody can show
 * was asked for is one that should not be made.
 */
final class CallbackForm
{
    public const NAME = 'callback';

    public static function definition(): FormDefinition
    {
        $configured = (array) config('gadya-cms.portal.callback.notify', []);

        return new FormDefinition(
            name: self::NAME,
            label: (string) config('gadya-cms.portal.callback.label', 'Call back'),
            rules: [
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{6,40}$/'],
                'message' => ['nullable', 'string', 'max:2000'],
                'consent' => ['accepted'],
            ],
            /*
             * Whoever hears about the contact form hears about this too,
             * unless someone chose otherwise: a call-back nobody is told
             * about is worse than none.
             */
            notify: FormDefinition::recipients(self::NAME, $configured) ?: FormDefinition::recipients('contact', (array) config('gadya-cms.forms.forms.contact.notify', [])),
            success: (string) config('gadya-cms.portal.callback.success', 'Thank you. We will call you shortly.'),
            analyticsEvent: 'lead_form_submit',
        );
    }

    /**
     * The consent sentence exactly as the visitor sees it.
     */
    public static function consentText(): string
    {
        $text = (string) config(
            'gadya-cms.portal.callback.consent_text',
            'I agree to {{ business }} calling me back about my enquiry on the number above. The call may be made by an automated AI assistant, and I can ask not to be called again at any time.',
        );

        return trim((string) preg_replace('/\{\{\s*business\s*\}\}/i', (string) config('gadya-cms.brand.name', config('app.name')), $text));
    }

    /**
     * What is kept when the box was ticked.
     *
     * @return array{text: string, at: string, ip: string|null}
     */
    public static function consentRecord(Request $request): array
    {
        return [
            'text' => Str::limit(self::consentText(), 1000, ''),
            'at' => now()->toIso8601String(),
            'ip' => ClientIp::for($request),
        ];
    }
}
