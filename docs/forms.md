# Forms

The site's forms post to the package. Only the configured fields are kept, under the rules given; a honeypot quietly drops bots; the enquiry is emailed with reply-to set to the sender, counted on the dashboard, and kept in **Content → Enquiries** - because an email can be lost, and the one from a fortnight ago is the one the client suddenly needs.

## Configure

```php
'forms' => [
    'honeypot' => 'website',
    'forms' => [
        'contact' => [
            'label' => 'Contact',
            'fields' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'message' => ['required', 'string', 'max:5000'],
            ],
            'notify' => ['hello@example.com'],
            'success' => 'Thank you. We will be in touch soon.',
            'analytics_event' => 'lead_form_submit',   // or null
        ],
    ],
],
```

## Template

```blade
<form method="POST" action="{{ route('gadya-cms.forms.store', 'contact') }}">
    @cmsForm('contact')
    <input type="hidden" name="_redirect" value="/thanks">   {{-- optional; same-site paths only --}}

    <label>Name <input name="name" value="{{ old('name') }}" required></label>
    <label>Email <input name="email" type="email" value="{{ old('email') }}" required></label>
    <label>Message <textarea name="message" required>{{ old('message') }}</textarea></label>

    <button>Send</button>
</form>

@cmsFormStatus('contact')
```

`@cmsForm` renders the CSRF token, the page the form is on, and the honeypot. `@cmsFormStatus` renders the success message or the form's own error bag (`gadya-cms.contact`), so two forms on one page never show each other's errors.

A request with `Accept: application/json` gets `{"ok": true, "message": "..."}` or a 422 with errors, for a form submitted by script.

Submissions are throttled to six a minute per address (`gadya-cms-forms` rate limiter).

## Automatic replies

**Settings → Automatic replies** holds the thank-you email each form sends back, in the client's own words. `{{ name }}`, `{{ business }}` and the name of any field are replaced; a blank line starts a new paragraph. Only sent when the form collected an email address.

## The mailing list

A sign-up box is not a contact form - it needs dedupe, an unsubscribe link and an export. See [Events, search and the mailing list](events-and-search.md).

## The inbox

Opening an enquiry marks it read; the navigation badge counts the rest. Enquiries can be archived, deleted, and downloaded as CSV with one column per field.
