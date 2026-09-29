# Forms

There are two kinds of form, and they share one inbox:

- **Forms the client builds** under **Content → Forms** - a drag-and-drop builder with a live preview - and places on any page herself, as a **Form** section, inside longer text with `[form:slug]`, or with a component in a template.
- **Forms a developer configures** under `forms.forms` in `config/gadya-cms.php` and writes the markup for. They work exactly as they always did.

Both post to `/cms/forms/{slug}`, both are kept in **Content → Enquiries**, emailed, counted on the dashboard and sent to the Gadya portal. A built form with the slug of a configured one takes its place the moment it is published - which is how a configured form is handed over to the client (below).

## Placing a form on a page

### As a section - the client does it

On a page's edit screen, **Add to sections**, choose **Form**, pick the form and, if she likes, a title and a few words above it. It is offered on every page type, and a Form section can be saved as a block like any other. Nothing is live until she publishes.

The site's template draws sections; for the package to draw the Form ones it needs **one line**, the first inside the loop:

```blade
@foreach ($page['sections'] ?? [] as $index => $section)
    @cmsSection($section, $index)

    <section class="page-section page-section--{{ $section['type'] }}">
        ...the site's own markup, unchanged...
    </section>
@endforeach
```

`@cmsSection` draws a section the package knows (today: `form`) and moves the loop on; every other kind falls through to the site's markup as before. It must sit directly inside the loop (it `continue`s it). Without an `$index` the section's title and intro cannot be edited in place.

To switch it on for an existing site:

1. `php artisan gadya:upgrade --phase=code` - the `cms.form-sections` step adds the line to every section loop written the ordinary way (`@foreach (... sections ... as $index => $section)` alone on its line) and says which files it changed. Commit them.
2. `php artisan gadya-cms:audit` - **Form sections are drawn on the page** is ✓, or names the loop it could not change for you to edit by hand.
3. Nothing else: the section kind is offered on every page as soon as the package is updated (`forms.builder.sections`, on by default).

The section renders as `<section class="cms-section cms-section--form">` with `cms-section__title` and `cms-section__intro`. A site can add section kinds of its own the same way: `app(\Gadya\Cms\Content\SectionRenderer::class)->extend('map', fn (array $section, ?string $path) => new HtmlString(...))`.

### Inside longer text

In any Markdown field rendered with `@cmsMarkdown` - a legal page, a long answer - the client writes `[form:catering-order]` on a line of its own and the form appears there.

### In a template

```blade
<x-gadya-cms::form form="catering-order" />
<x-gadya-cms::form form="catering-order" title="Order catering" intro="We need a week's notice." class="catering" />
@cmsFormEmbed('catering-order')
<x-gadya-cms::form-popup form="quote" button="Get a free quote" />
```

The popup is a button opening the form in a native `<dialog>` (Escape closes it, focus returns to the button; without JavaScript the form shows in the page). A form that is not published renders nothing for a visitor; for an editor it says so.

### Its own page, and other websites

Every form has a page at `/forms/{slug}` (`forms.builder.path`), for a link in a text, an email or a QR code - kept out of search engines unless the client turns that off. It is a plain page of its own; set `forms.builder.layout` to the site's layout (it must yield `content`, and is given `$page` and `$site` like the blog) to wear the site's header and footer.

`/forms/{slug}/embed` is the form alone, for an iframe on another website. The form's **Share** screen gives the snippet, which resizes the iframe to the form. Inside someone else's page the browser sends no cookies, so the embed posts back to itself without a session; the seal, the honeypot and the rate limit stand in for the CSRF token.

### In the live editor

With the editor on, a form on the page carries **Edit this form** (to its builder), and in a Form section **Choose another form**, which swaps it in the draft.

## Building a form

**Content → Forms**: **Start from a template**, **Describe your form** (the site's AI drafts one), **Make a site form editable** (below), or **Start from scratch**. Every form starts as a draft; **Duplicate** copies one as a draft, **Archive** puts it away (its enquiries and figures stay, and it can be brought back).

Templates: contact, request a quote, book an appointment, catering order (three steps, delivery address only when delivering), job application, event RSVP, feedback with a star rating and the 0-10 "would you recommend us", newsletter sign-up, and call me back.

The edit screen has the questions beside a live preview, then **Details** (name, address, status), **After sending** (the thank-you or a page to go to, in each language, the button words, finishing later), **Emails and texts**, **Webhooks**, **Also save to** (when the site has destinations, below) and **Settings** (its own page, the built-in look, a CSS class, Turnstile, the dashboard event, whether it is a call-back form). Every save of a changed form is kept as a numbered version, and each enquiry notes the version it answered.

### Kinds of question

| Group | Kinds |
| --- | --- |
| Text | short answer, long answer |
| Contact | email, phone, web address, name (first and last), address (street, apartment, city, US state, ZIP), country, US state |
| Numbers | number, amount of money, star rating, scale (0 to 10, for NPS), slider |
| Choices | dropdown, dropdown with several answers, one of a list, tick all that apply, a single tick box, yes or no |
| Date and time | date, time, date and time |
| Files | file upload, photo upload |
| Special | hidden value, consent, signature, join the mailing list |
| Layout | heading, paragraph, divider, new step |

Each question has its words, help under it, an example answer in the box, whether it must be answered, full or half width, what it starts filled in with, and its own checks: shortest and longest answer and a pattern for text, smallest and largest for numbers, today-or-later for dates, allowed kinds of file and the largest size for uploads. **Can be filled in from the web address** takes the answer from `?key=value` - a hidden `campaign` field on a link in a flyer, say. **Name in the inbox and exports** is the key the answer is kept under; it is made from the question and kept when the question is reworded.

A **consent** question keeps the exact words the visitor agreed to (in the language they read them), when (`at`, ISO 8601), from which address (`ip`), and which privacy policy: its address (`policy_url`, from **Settings → Privacy choices** or `privacy.policy_url`) and its version (`policy_version`). The version is `privacy.policy_version` in config when the site numbers its policy; otherwise, when the policy is a page of the CMS, the moment its current wording went live (the oldest published revision in which the page reads as it does now); otherwise nothing. On a **call-back form** a ticked consent is sent to the Gadya portal as consent to be rung back (see [The Gadya portal](portal.md)). The record is kept under `meta.consents.{key}` and reaches destinations as `@consent.*` (below).

**Join the mailing list**, ticked on a form that asks for an email address, adds them to **Content → Mailing list**.

### Showing and hiding

Any question, and any step, can be shown only when - or hidden when - all or any of a few conditions are true: an earlier answer *is*, *is not*, *contains*, *does not contain*, *is empty*, *is filled in*, *is more than*, *is less than*. A choice matches its words or its key, in any case. The rules run in the browser as the visitor answers and again on the server when the form is sent: a hidden question is never required, and its answer is never kept, whatever the browser sent. A question that reads a hidden one reads it as empty.

### Steps

A **New step** starts a step, titled by its words (the first step's title is under **After sending**). The visitor sees a progress bar, **Back** and **Next**; each step is checked before moving on - by the browser, then by the server, so a bad email is caught on step one, not step three. Steps hidden by their rules are skipped. Without JavaScript every step shows at once as one long form, and the server checks it all.

### Finishing later

What a visitor types is kept in their browser as they go and put back if they leave and return, with **Start again** beside it (**Keep what they type on their device**, on by default). **Offer "email me a link to finish later"** adds a link that emails them a signed link, good for `forms.builder.resume_days` (7); it works on any device, lands back on the page the form was on with their answers in it, and stops working once the form is sent. Files and signatures are never kept.

### Spam

Every form has the honeypot and the rate limit. A built form also carries a sealed timestamp: sent within `forms.builder.min_seconds` (3) of being drawn, or with a tampered seal, it is thanked and ignored. A post without the seal - hand-written markup left from before a form was converted - is let through. For a form that still gets spam, put the keys of a Cloudflare Turnstile widget under **Settings → Spam protection** and switch **Turnstile** on in the form's settings; if Cloudflare cannot be reached, the visitor is let through rather than losing the enquiry.

## What a visitor sees

Unstyled, semantic markup: every input has a label, a question with several inputs is a `<fieldset>` with a `<legend>`, help and errors are tied to their input with `aria-describedby`, an input with an error has `aria-invalid`, and on an error the summary at the top takes focus with a link to each question. Big touch targets, `autocomplete` and `inputmode` on names, emails, phones and addresses. Classes start `cms-bform__` (`__field`, `__label`, `__input`, `__error`, `__step`, `__progress`, `__submit`, ...), and the form has `cms-bform--{slug}` plus its own CSS class.

A small default stylesheet comes with the first form on a page, coloured from **Look & feel** through `--cms-form-accent` and `--cms-form-ink`. Every rule sits under `:where()`, so anything the site writes wins; turn it off per form, or everywhere with `forms.builder.styles`.

A single-step form works without JavaScript. The script - vanilla, inlined once per page, with the page's CSP nonce - adds steps, show and hide, keeping progress, sending without a page load, and the form's figures.

Answers are kept in the site's own language; a translated form's choices are stored as the site's own words. A form carries a hidden `_locale` (the language it was drawn in) and hidden `_attribution[...]` inputs the script fills with where the visit began.

## Where each enquiry goes

- **Emails** (**Emails and texts**): a list of addresses told the moment one arrives, with a subject and a few words of the client's own. Replying goes to the sender.
- **Rules** send some enquiries elsewhere: "if *What do you need?* is *Catering*, also email catering@..." - each rule with its own addresses and mobile numbers, in addition to the usual list or **instead** of it.
- **Text alerts**, through the client's own Twilio account (below).
- **The reply** to the sender, in the client's words, when the form asked for an email address.
- **The panel's bell**, for everyone who reads enquiries (it needs Laravel's `notifications` table).
- **The site's own records** - a `Lead` in its own table, a CRM - through the form's **destinations** (below).
- **Webhooks** (below) and **the Gadya portal**, which is given the name, email, phone and message by the kind of question rather than its name, and where it came from as `attribution` beside them.

Merge tags in any of these: `{name}`, `{business}`, `{form}`, `{all_answers}` and `{key}` of any question. `{{ name }}` works too.

### Text alerts

Texts are sent from the client's **own** Twilio account: under **Settings → Text messages** she enters the Account SID, the auth token (stored encrypted, never shown back) and the Twilio number or a Messaging Service SID, and can **Send a test text**. Her Twilio number must be registered for business messages - toll-free verification, or A2P 10DLC for a local number - or the networks may block the texts.

Then each form (and each rule) takes a list of mobile numbers, typed however people type them and kept as E.164. The text says *"New Catering order enquiry from Pat Jones: We need food for forty… https://.../admin/submissions"*. The first text to each number ends with "Reply STOP to opt out"; a number that replies STOP (Twilio error 21610) is marked as opted out, shown so beside the number, and never tried again until its owner texts START. Texts go on the queue, are tried again when Twilio answers with a 5xx, never when it refuses one, and can never affect the visitor.

### Webhooks

Each enquiry is posted as JSON to every webhook on the form - for Zapier, Make or the client's own system:

```json
{
  "event": "form.submitted",
  "form": {"slug": "catering-order", "title": "Catering order", "version": 3},
  "submission": {"id": 812, "submitted_at": "2026-09-28T14:02:11-04:00", "page": "/catering", "sender": "Pat Jones"},
  "data": {"name": "Pat Jones", "guests": "40", "menu": ["Hot trays", "Desserts"]},
  "labels": {"name": "Your name", "guests": "Number of guests", "menu": "What would you like?"},
  "site": "https://example.com"
}
```

With a secret, the request carries `X-Gadya-Signature: sha256=<HMAC-SHA256 of the body>`. A 2xx is delivered; most 4xx are marked failed at once; a timeout, 5xx or 429 is tried again, `forms.builder.webhooks.tries` (5) times over about half an hour. Every attempt is logged on the form's **Figures**. Addresses on the server's own network are refused.

## Saving enquiries to the site's own records

A site whose own controller did more than store and email an enquiry - saved a `Lead` with a status, the brand, the campaign and the consent - keeps doing all of it with a **destination**. The enquiry goes into the inbox first, as always; then each destination the form names runs, straight away, before anyone is emailed.

Most need no PHP. Describe the model in config:

```php
'forms' => [
    'builder' => [
        'destinations' => [
            'leads' => [
                'label' => 'Leads',
                'model' => App\Models\Lead::class,
                'map' => [
                    'name' => 'name',
                    'email' => 'email',
                    'source' => '@utm_source',
                    'landing_page' => '@landing_page',
                    'brand' => '@site',
                    'consented_at' => '@consent.at',
                    'privacy_version' => '@consent.policy_version',
                    'locale' => '@locale',
                ],
                'defaults' => ['status' => 'new'],
            ],
        ],
    ],
],
```

Each value in `map` is one of:

- **the key of a question** (`'email'`): its answer, as kept in the inbox (a choice's words, a name joined up, "Yes" for a tick). A question they did not answer gives nothing - never the key's own name;
- **a token** starting with `@`, from where the enquiry came from and what they consented to;
- **anything else**, as written (`'new'`); start it with `=` to keep it as written where a question has that key (`'=email'`).

`defaults` fill an attribute the map left empty. An attribute left with nothing is not set at all, so the table's own default applies. Attributes are set directly (not through `$fillable`): the developer chose them, and the client can only map the attributes listed in `map` or the model's `$fillable`. A moment (`@consent.at`, `@submitted_at`) is written as a date, whether or not the model casts it; a list of answers is joined with commas unless the model casts that attribute.

| Token | |
| --- | --- |
| `@utm_source`, `@utm_medium`, `@utm_campaign`, `@utm_term`, `@utm_content`, `@gclid`, `@fbclid`, `@msclkid` | the campaign that brought them, first touch |
| `@source` | the campaign's source, else the ad network whose click it was (`google-ads`, `facebook-ads`, `microsoft-ads`), else the site that sent them, else `direct` |
| `@referrer`, `@landing_page`, `@page_url` | the site that sent them, the first page of their visit, the page they sent the form from |
| `@locale`, `@site` | the language they filled it in, the brand they were on |
| `@user_agent`, `@ip` | their browser, their address (as the privacy settings keep it) |
| `@consent.given`, `@consent.text`, `@consent.at`, `@consent.ip`, `@consent.policy_version`, `@consent.policy_url` | the first consent question ticked; `@consent.{key}.at` and so on for a particular one |
| `@name`, `@email`, `@phone`, `@message` | the answer to that kind of question, whatever it is called |
| `@form`, `@form_title`, `@submission_id`, `@submitted_at`, `@answers`, `@attribution` | the form, the enquiry, every answer as text, all of where it came from |

On the form, **Also save to** chooses the destinations. Choosing one fills in a mapping from this form's questions - a question with the same name or a common other name (`phone`, `tel`, `mobile`), a question of the right kind (an email address for `email`), a token the field's name points at (`utm_campaign`, `landing_page`, `consented_at`, `privacy_version`, `brand`, `locale`), and whatever the developer mapped in config - and **Match to this form's questions** does it again. The client can change any line for this form; a line left empty leaves that field out.

**If a destination fails**, the failure is reported (to the site's error tracking, as `report()` does), the next destination still runs, and the visitor is thanked all the same. Each enquiry notes how each went, under `meta.destinations` - `{"leads": {"ok": true, "result": "Lead #12", "at": "..."}}` or `{"ok": false, "error": "QueryException: ..."}` - and the inbox shows it as **Saved to Leads**, or **Not saved** and why. It runs in the request rather than on the queue, so the site's own record exists as soon as the visitor is thanked.

### A destination of the site's own

For what a mapping cannot say - a call to a booking system's API, a payment - write a class:

```php
namespace App\Forms;

use Gadya\Cms\Forms\Destinations\FormDestination;
use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Facades\Http;

class SendToBookingSystem implements FormDestination
{
    public function key(): string { return 'bookings'; }

    public function label(): string { return 'The booking system'; }

    /** Nothing to map: it decides what to send. */
    public function fields(): array { return []; }

    public function handle(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): mixed
    {
        $response = Http::timeout(10)->post('https://api.bookings.example/v1/enquiries', [
            'name' => $context->token('@name'),
            'email' => $context->token('@email'),
            'date' => $data['date'] ?? null,
            'source' => $context->source(),
        ])->throw();

        return 'Booking '.$response->json('id');
    }
}
```

and name it in config: `'destinations' => ['bookings' => App\Forms\SendToBookingSystem::class]` (or `['class' => ..., ...]`, whose array is given to its constructor as `$config`). A package can `app(FormDestinations::class)->register(new ...)` instead. Throw when it fails - the enquiry notes it and the visitor never sees it. Keep it quick; queue a job from it for anything slow.

### Listening instead

Every enquiry - through a built form or a configured one - dispatches `Gadya\Cms\Events\FormSubmitted` once it is kept (and after a built form's destinations): `$event->submission`, `$event->data` (the answers), `$event->context` (a `SubmissionContext`: `token('@utm_source')`, `source()`, `site()`, `locale()`, `consents`), and `$event->form` (a built form) or `$event->definition` (a configured one). A listener that throws is reported without reaching the visitor. It suits "also fire our own `LeadReceived` event" better than a destination.

## Where each enquiry came from

Every enquiry keeps, under `meta.attribution`:

- the campaign: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and the ad clicks `gclid`, `fbclid`, `msclkid`;
- `referrer` (the other site that sent them), `landing_page` (the first page of their visit) and `page_url` (the page they sent it from);
- `locale`, `site` (the brand), `user_agent` and `ip`.

It is the **first touch**: the forms script notes the campaign, the referrer and the landing page in the tab's `sessionStorage` on the first page with a form it sees, and posts them with the form in hidden `_attribution[...]` inputs. The landing page is only noted by the script when that page is where the visit began (nothing, or another site, sent them there). The server fills in whatever the script did not send - no JavaScript, or the form on a later page - from the first request of the visit, which the session notes (`RememberFirstTouch`, in the `web` group), then from the address of the page the form was sent from. A referrer on the site itself, or a "landing page" on another site, is not believed.

`site` is the CMS site's key unless `forms.builder.attribution.site` says otherwise: a map of host to brand (`['aleksey.com' => 'aleksey', 'avantgarde.com' => 'avantgarde']`), or an invokable class given the request. (A closure works too, but not with `config:cache`.) `ip` is kept in full, `masked` (the last part blanked) or not at all (`none`), under `forms.builder.attribution.ip`; it is always masked for someone who refused analytics in the privacy banner or whose browser sends Global Privacy Control. `forms.builder.attribution.enabled => false` keeps none of it.

It shows in the inbox under each enquiry, as columns after the answers in both downloads, reaches destinations as tokens, and goes to the portal as `attribution` beside the answers (without the address and the browser). Configured forms keep it too. Why this is not analytics, and what it means for the privacy banner: [Privacy choices](privacy.md).

## The inbox

Opening an enquiry marks it read; the navigation badge counts the rest. An enquiry moves on from there as the client deals with it:

- **Mark answered** once someone has replied. The date is kept, so "how long did we take?" has an answer.
- **Notes and follow-up** holds internal notes - never sent to anyone - and a date to come back to it. Enquiries due a follow-up are highlighted, can be filtered to, and are listed in the drift digest.
- **Archive** puts it away.

A built form's enquiry shows each answer under its question, what the visitor consented to, and its files. **Download a form's enquiries** gives one form's enquiries as CSV or Excel, a column per question headed with its words; a cell a spreadsheet would run as a formula is written as text. Excel files come from OpenSpout, which Filament already requires. Enquiries can still be selected and downloaded as CSV.

### Uploads

Uploads and signatures are kept privately: on the photo library's disk when that is a cloud disk (with private visibility), and on the `local` disk otherwise - never in a public folder (`forms.builder.uploads.disk` to choose). Files have random names; the inbox opens them through signed links that work for half an hour, for someone allowed to read enquiries. Deleting an enquiry deletes its files.

## Figures

Each form's **Figures**: how many people saw it, started it and sent it, the conversion rate, how many reached each step of a long form and gave up there, the average time to fill it in, the pages it was on and where people came from, and the latest webhook deliveries. The dashboard has a **Forms** card with the busiest.

They are counted the way the rest of the dashboard is ([Analytics](analytics.md)): a daily identifier, never an address. Editors, previews and bots are not counted. Someone who refused analytics in the privacy banner is not counted seeing or starting a form; their enquiry is counted without an identifier. Views need JavaScript; sends are always counted. Old figures are pruned by `gadya-cms:prune-analytics`.

```php
app(\Gadya\Cms\Analytics\FormAnalytics::class)->for($form, 30);   // views, starts, completions, conversion, steps, average_seconds, sources, pages
```

## More than one language

A built form is translated like an article: its title, questions, choices and words are an overlay kept as `form:{id}`, drawn in the visitor's language, included in **Translate the whole site** and **Translate into Spanish** on its row. Keys, rules and logic are never sent for translation. Validation messages go through Laravel's translator. See [More than one language](multilingual.md).

The visitor's language is the CMS's own on a page it serves in another language (`/es/contact`), and otherwise **whatever the app says** - `App::getLocale()` - so a site that chooses the language itself (from the session, a cookie, or a `/{locale}` prefix of its own routes) gets its forms in that language without listing it under `locales.enabled`. The overlay applies for any language a translation exists in (`ru`, `uk`, ...). The form's own words are the ones in `locales.default`: set it to the site's own default language. The form posts the language it was drawn in (`_locale`), so the errors and the thank-you come back in it even though the app's locale middleware never sees the package's address; only a language the site speaks is taken from the post (a CMS language, one with a `lang/` directory or JSON file, or one the form is translated into).

Under **After sending → In other languages**, each language can have its own thank-you and its own page to go to (`/ru/spasibo`). Without one, the thank-you is the translated one, and the page the form's own. Without JavaScript the thank-you is flashed, in that language, for the page they land on.

Answers are kept in the site's own words whatever language the form was filled in; the consent wording is kept as the visitor read it; the language is kept as `meta.locale` and `@locale`.

## Who may do what

The `forms` ability builds, changes and places forms; `enquiries` reads them. A role that may edit content may build forms too; give `forms` alone to someone who should build forms but not touch the pages. **Text messages** and **Spam protection** need `settings`. See [Roles and abilities](roles-and-globals.md).

## Configured forms

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

`@cmsForm` renders the CSRF token, the page the form is on, and the honeypot. `@cmsFormStatus` renders the success message or the form's own error bag (`gadya-cms.contact`), so two forms on one page never show each other's errors. A request with `Accept: application/json` gets `{"ok": true, "message": "..."}` or a 422 with errors. Submissions are throttled to six a minute per address (`gadya-cms-forms`; checking one step of a built form has its own, larger allowance).

**Settings → Enquiry emails** says, per configured form, who is emailed in addition to `notify`, and holds each form's thank-you email.

A form written as a Livewire component, or any other way, can still keep its enquiries in the inbox and send the same emails:

```php
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\StoreFormSubmission;

app(StoreFormSubmission::class)->handle(FormDefinition::find('contact'), $validated, request());
```

## Handing a configured form to the client

**Content → Forms → Make a site form editable** (or `php artisan gadya-cms:forms:convert contact`) copies a configured form into the builder as a draft, with the same slug and every field under the same name, its `notify` addresses, its thank-you email and its analytics event. The configured form keeps answering until the copy is published; from then on the built form answers the same address, so enquiries, emails and figures carry on - and so does hand-written markup posting to it. Replace that markup with `<x-gadya-cms::form form="contact" />` when convenient, and remove the config entry only once the built form is verified.

For an agent: `php artisan gadya-cms:forms:scan --json` finds every form on the site - configured, `<form>` markup in the templates (with the fields, labels, kinds and choices read from it) and Livewire components that call `StoreFormSubmission`. `php artisan gadya-cms:forms:convert {form} [--from=blade --file=...] [--destination=...] [--write-config] [--dry-run] [--json]` builds the draft and prints the replacement line; it never changes a template. The `gadya-cms-forms` Boost skill walks through it.

### A form that posts to the site's own controller

The scan follows a `<form>` whose `action` is the site's own - `route('contact.send')`, a helper of the site's own such as `locale_route('contact.submit')`, `action([ContactController::class, 'send'])`, `action(ContactController::class)`, `url('/contact')` - to the controller method behind it and reads it (never runs it). A route is found by its name through the running router, so one whose address is worked out in code (`Route::post($uri('contact.submit'), ...)` in a localised group) is found as the form names it; when the router does not have it, `routes/*.php` are read, with the names their groups give (`->name('avant.')`, `'as' =>`, `"localized.{$locale}."`). An invokable controller - `ContactController::class`, `[ContactController::class]`, its class name in a string - is its `__invoke`. Each such form carries:

- `handler`: the `action`, `file` and `line`; `writes` (each Eloquent `create`, `updateOrCreate`, `new ...->save()`, with the attributes and the expression each is set from); `events` fired; `redirect` (where to, and the flashed message or its lang key - when the method has several, one per brand say, `redirects` lists them and `redirect` is the one this form meets: the one going back to the same part of the site, or whose condition names it); `notify` - who it emails (`Mail::to(...)`, `Notification::route('mail', ...)`), with a `config()` or `env()` recipient worked out in the running app (`emails`, `from`, and `unresolved` for any not set there); whether it deals with `consent` or a privacy policy (and `policy_version_config`, the config key it read the version from); whether it keeps `attribution`; `emails`; `custom` - what no destination can do (a call to another service over HTTP, a payment, a third-party SDK); and `does`, all of it in plain words.
- `suggested_destination`: a destination for config that does what the record the controller creates does - its model, each attribute mapped to a question or a token (`session('utm_source')` becomes `@utm_source`, `now()` for `consented_at` becomes `@consent.at`, `config('privacy.version')` becomes `@consent.policy_version`, `app()->getLocale()` becomes `@locale`, the host or the brand becomes `@site`), fixed values as `defaults` (`'status' => 'new'`), and `unmapped` for expressions it could not place.

The scan's `notices` say when the panel has forms switched off (`->forms(false)`), which hides the builder and the inbox.

`forms:convert --from=blade --file=... --destination=leads --write-config` then builds the draft from the template (the template decides the questions; a configured form of the same name is not consulted), adds the suggested destination to `config/gadya-cms.php` under `forms.builder.destinations` - with `--dry-run`, it prints the diff instead and changes nothing - and chooses it for the form. `--write-config` never changes an entry already there. `--destination` alone chooses a destination the config already has.

Words the template writes as `{{ __('contact.name') }}`, `@lang('contact.name')` or `{{ trans('contact.name') }}` - in labels, placeholders, `aria-label`s, choices, the send button, and the message the controller flashes - are read from the site's `lang/` files (`lang/ru/contact.php` and `lang/ru.json` alike) in every language they have: the words of `locales.default` become the form's own, and each other language becomes the form's translation, live with the form. A JSON-style key (`__('Building work')`) is its own words in the default language. A language that lacks a key shows the default language's words there. The command's JSON has them under `translations`.

What the template says is read as it reads to a visitor:

- **A label** is only the words of its `<label>` (by `for`, or wrapped around the control), a group's `<legend>` or an `aria-label` - never a dropdown's choices, an `@error` message or a directive beside it. A label mixing keys and words, such as a consent sentence with a link to the policy in it, is put together in every language.
- **A required mark** - `*`, `<span>*</span>`, `(required)`, `<abbr title="required">*</abbr>`, in the template or in the lang files' words (`'name' => 'Name *'`) - is taken off in every language and makes the question required.
- **Words written out** in the template ("Name *") that are word for word a string in the default language's lang files are given that string's translations; the JSON's `lang_matches` lists each match to check.
- **Choices drawn in a `@foreach`** are written out, in every language, when what the loop runs over can be worked out in the running app: `__('file.key')` (an array in the lang files), `config('x')`, an array in `@php`, a static call (`SiteContent::services()`), or a variable the page's controller, route closure or view composer passes the template (`view('pages.contact', [...])`, `compact()`, `->with()`). Only expressions that read are run - no assignment, `new`, closure, or call whose name writes, sends or deletes. When it cannot be worked out, the question is still a dropdown (or radio buttons, or tick boxes) with the choices written out beside the loop, and `unmapped` says where its choices come from, to fill in. The empty first choice ("Not sure yet") becomes the dropdown's placeholder.

And what the old form did around its questions comes with it: the **staff emails** the controller sent to (`settings.notify`, listed in the dry run to confirm), the **analytics event** from a `data-analytics`/`data-event` attribute on the form or its send button, a `gtag('event', ...)` or `dataLayer.push({event: ...})` in the template's script, or the controller (`settings.analytics_event`), the **thank-you** the controller flashed, in every language (`success`), and the **consent wording** in every language (`consent.wording`). The privacy policy the consent box linked to is suggested as `privacy.policy_url` (`suggested_config`) when none is set.

## For developers

```php
use Gadya\Cms\Forms\Builder\FieldType;
use Gadya\Cms\Forms\Builder\FieldTypes;

app(FieldTypes::class)->register(
    FieldType::make('vin', 'Vehicle VIN')
        ->group('Text')
        ->rules(fn (array $field): array => ['' => ['string', 'size:17']])
        ->normaliseUsing(fn (mixed $value): string => strtoupper((string) $value))
        ->view('forms.fields.vin'),   // given $field, $id, $name, $value, $describedBy, $invalid, $form
);
```

A registered kind appears in the builder, is drawn, checked and kept like the built-in ones. Also: `FormDestinations` (`register()`, `all()`, `run()`), `DestinationMap` (`resolve()`, `suggest()`), `SubmissionContext::fromSubmission($submission)`, `Attribution`, `FormLocale`, `PolicyVersion`, `Form::findLive($slug)`, `$form->schema()` (`fields()`, `inputs()`, `steps()`), `FormLogic::visible($schema, $input)`, `FormRenderer::render($slug, $options)`, `SubmitBuilderForm`, `FormTemplates`, `FormGenerator` (the `FormWriter` agent) and `FormWebhooks::sign($body, $secret)`.

```php
'forms' => [
    'builder' => [
        'enabled' => true,
        'path' => 'forms',
        'layout' => null,
        'sections' => true,
        'styles' => true,
        'min_seconds' => 3,
        'resume_days' => 7,
        'max_steps' => 12,
        'max_fields' => 100,
        'uploads' => ['disk' => null, 'directory' => 'form-uploads', 'max_kb' => 20480],
        'webhooks' => ['tries' => 5, 'timeout' => 10],
        'destinations' => [],
        'attribution' => ['enabled' => true, 'site' => null, 'ip' => 'full'],
    ],
],
'privacy' => [
    'policy_url' => null,
    'policy_version' => null,
],
```

In tests, fake the AI like any other agent:

```php
use Gadya\Cms\Ai\Agents\FormWriter;

FormWriter::fake([['title' => 'Catering order', 'fields' => [['type' => 'name', 'label' => 'Your name', 'required' => true]], 'success' => 'Thank you.']]);
```

Texts and webhooks fake with `Http::fake(['api.twilio.com/*' => ..., 'hooks.example.com/*' => ...])`.

## The mailing list

A sign-up box is not a contact form - it needs dedupe, an unsubscribe link and an export. See [Events, search and the mailing list](events-and-search.md).
