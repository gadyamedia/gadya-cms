---
name: gadya-cms-forms
description: Convert a Gadya CMS site's existing forms - configured under forms.forms, hand-written <form> markup in Blade templates (including ones that post to the site's own controller, which saves a lead, keeps the campaign and consent, and thanks the visitor in their language), or Livewire components that call StoreFormSubmission - into builder forms the client can edit under Content → Forms, then place them with <x-gadya-cms::form> or a Form section. Use when asked to convert, migrate or "make editable" a site's forms.
---

# Converting a site's forms to builder forms

## When to use this skill

Use it when asked to move a site's forms into the form builder, to make a form "editable by the client", or to replace hand-written form markup. The full reference is `references/forms.md` (also `vendor/gadya/cms/docs/forms.md`); read it first.

A builder form keeps the configured form's **slug and every field's name**, so the enquiries already in the inbox, the people told about new ones, the thank-you email and the analytics event all carry on. A built form is a **draft** until published, and the configured form keeps answering until then - converting breaks nothing.

## Rules

- **Branch first, never push.** Start from a clean tree (`git status --short` is empty): `git switch -c chore/convert-forms`. Pushing often deploys; leave that to the person.
- **Never delete a form's config definition** (`forms.forms.{name}` in `config/gadya-cms.php`) until the converted form is published and verified on the page. Removing it is a separate, later commit - and only when asked.
- **The commands never change templates**, and change `config/gadya-cms.php` only with `--write-config` (read the `--dry-run` diff first). You change the markup, and a panel's `->forms(false)`, yourself, after reviewing what was built.
- Content is the client's: never run `migrate:fresh` or `db:seed`, never write to `gadyacms_*` tables by hand. Converting creates a draft through the command; publishing is a person's decision in the panel unless they asked you to publish.
- Follow the application's `.ai/rules`, CLAUDE.md or AGENTS.md. Run Pint on PHP you touch.

## 1. Scan

```bash
php artisan gadya-cms:forms:scan --json
```

Each entry has `kind` (`config`, `blade`, `livewire`), `name` (the package form it posts to, or null), `file` and `line`, the `fields` found (type, label, required, options, or the validation `rules`; `label_key`, `placeholder_key` where the words are lang keys), the `config` definition if any, whether a `builder` form exists already, and a `suggested_slug`. The top-level `notices` list anything that stops a converted form working - a panel with `->forms(false)`.

- A `blade` form with a `name` posts to the package (`route('gadya-cms.forms.store', 'name')` / `@cmsForm('name')`) and usually has a matching `config` entry: convert from config and read the labels from the template.
- A `blade` form with no `name` posts to a controller of the site's own. The scan reads it for you: `handler` (what it does, in `does`) and `suggested_destination`. Follow **When the controller does more** below.
- A `livewire` form: read the component. Convert it only when it does nothing but validate and hand over to `StoreFormSubmission`; otherwise treat it like a controller (below), reading the component yourself.

## When the controller does more

A site's own controller often does more than store and email the enquiry: it saves to the site's own model (a `Lead` with a status and its own panel screen), records the brand, the page it came from and the ad campaign, stores when consent was given and which privacy-policy version, and thanks the visitor in their language. A builder form does all of that generically - so convert it, don't stop.

1. **Read what it does.** `handler.does` says it in plain words; `handler.writes` has each record it creates with the expression behind each attribute; `handler.redirect` the message it flashes; `handler.custom` anything no destination can express. Open the method at `handler.file:handler.line` and check.
2. **Configure a destination that mirrors it.** `suggested_destination.config` is the block for `forms.builder.destinations`: the model, the `map` (questions by key, `@` tokens for the rest) and `defaults` such as `'status' => 'new'`. Check each line against the controller and fix what the reader guessed wrong. Keep the campaign and consent through tokens - `@utm_source` (or `@source`), `@landing_page`, `@referrer`, `@page_url`, `@site`, `@locale`, `@consent.at`, `@consent.policy_version`, `@consent.policy_url` (the full list is in `references/forms.md`). Anything in `suggested_destination.unmapped` needs a decision: map it, give it a default, or leave it to the column's default.
   - The brand: when one app serves several brands, set `forms.builder.attribution.site` (a map of host to brand, or an invokable class) so `@site` says which.
   - The policy version: if the controller read it from config (`handler.policy_version_config`), set `gadya-cms.privacy.policy_version` to the same value (for example `env()` or that config key); otherwise the CMS dates a CMS policy page itself. Set `privacy.policy_url` (or **Settings → Privacy choices**) to the policy's address.
   - The language: set `gadya-cms.locales.default` to the site's own default language (`app.locale`). Forms follow `App::getLocale()`; the site does not need to list its languages under `locales.enabled`.
   - Events the controller fires (`handler.events`): fire them from a listener on `Gadya\Cms\Events\FormSubmitted`, or the model's `created` event, in the app.
3. **Import the translations.** When the template's words are `__()`, `@lang` or `trans()` keys, the convert command reads them from `lang/` in every language - labels, placeholders, choices, the send button and the controller's flashed message - and writes the default language into the form and each other language as its live translation. Check `translations` in the JSON.
4. **Switch forms on in the panel.** If the scan's `notices` mention `->forms(false)`, change it to `->forms()` in that panel provider yourself (the command never edits it) - without it there is no builder and no inbox.
5. **Convert** - dry run first, and let it write the destination only when you have read the diff:

   ```bash
   php artisan gadya-cms:forms:convert contact --from=blade --file=resources/views/pages/contact.blade.php --destination=leads --write-config --dry-run --json
   php artisan gadya-cms:forms:convert contact --from=blade --file=resources/views/pages/contact.blade.php --destination=leads --write-config
   ```

   `--write-config` adds the suggested block to `config/gadya-cms.php` (never changing an entry already there); edit it there if the mapping needs fixing. `--destination` chooses it for the form. The client can adjust the mapping per form under **Also save to**.
6. **Point the markup at the builder form** as in step 4 below. Per-language thank-yous and pages to go to are under **After sending → In other languages**; set them if the controller redirected somewhere different per language.
7. **Verify** a test submission lands in **both** the inbox **and** the site's own model, with the source and consent columns filled (`source`, `landing_page`, `consented_at`, `privacy_version` - whatever the site keeps), and that the enquiry in the inbox says **Saved to Leads**, not **Not saved**. Check a submission in each language too.
8. **Keep the old controller route** until that is verified. Removing it (and the controller) is a separate commit, later, when the person agrees.

Still **stop and ask** when the controller does something no destination can express - a payment, a call to a third-party API or SDK, a booking made elsewhere (`handler.custom` lists what the reader saw). Then the answer is usually a small destination class in the app, which the person should agree to:

```php
namespace App\Forms;

use Gadya\Cms\Forms\Destinations\FormDestination;
use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Facades\Http;

/**
 * Sends each enquiry to the booking system, as ContactController::book() did.
 */
class SendToBookingSystem implements FormDestination
{
    public function key(): string
    {
        return 'bookings';
    }

    public function label(): string
    {
        return 'The booking system';
    }

    public function fields(): array
    {
        return [];
    }

    public function handle(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): mixed
    {
        $response = Http::timeout(10)->post(config('services.bookings.url'), [
            'name' => $context->token('@name'),
            'email' => $context->token('@email'),
            'date' => $data['date'] ?? null,
            'source' => $context->source(),
        ])->throw();

        return 'Booking '.$response->json('id');
    }
}
```

Register it with `'destinations' => ['bookings' => App\Forms\SendToBookingSystem::class]` under `forms.builder`, choose it with `--destination=bookings`, and test it with `Http::fake()`. A destination that throws is reported and noted on the enquiry; the visitor is still thanked - so check **Not saved** never appears in the inbox after the test submission.

## 2. Review

For each form, open the template at `file:line` and check the scan against it: fields drawn in a loop, a component or JavaScript are not seen by the scan. Note the wrapper's CSS classes and any custom styling of the form.

## 3. Convert - dry run first

```bash
php artisan gadya-cms:forms:convert contact --dry-run --json
php artisan gadya-cms:forms:convert contact --from=blade --file=resources/views/pages/contact.blade.php --dry-run --json
```

`--from=blade --file=...` reads labels, kinds of field and choices from the template; with a config definition too, the config decides which fields exist (a template field the config never kept is left out, with a warning) and the template supplies the words. Check `fields`, `settings.notify`, `settings.analytics_event` and `warnings`. Then run it without `--dry-run`. It prints the replacement line:

```blade
<x-gadya-cms::form form="contact" />
```

## 4. Replace the markup

- Replace the whole `<form>...</form>` - and the `@cmsFormStatus('name')` near it, which the component now does itself - with the component. Keep the page's surrounding layout: headings, wrappers, columns stay.
- Move custom classes from the old `<form>` onto the component (`<x-gadya-cms::form form="contact" class="contact-form" />`) or, so the client keeps them, onto the form's **Settings → Extra CSS class** in the panel.
- Where the site styled the old inputs, map those rules onto the `cms-bform__*` classes (`cms-bform__input`, `cms-bform__label`, `cms-bform__submit`, ...), or turn off the built-in look (**Settings → Use the simple built-in look**) if the site styles forms entirely itself.
- Where the page is built from sections and the client should be able to move the form, place it with a **Form** section instead: make sure the section loop has `@cmsSection($section, $index)` as its first line (`gadya-cms:audit` checks; `php artisan gadya:upgrade --phase=code` adds it), then add the section in the panel - that is content, so list it for the person unless they asked you to do it.

## 5. Verify

```bash
php artisan test --compact
```

Then check it for real: the page renders the form, and a test submission lands in **Content → Enquiries** under the same form name, with the notify emails sent. For a form with a destination, it also lands in the site's own model, with the source and consent columns filled. While the built form is still a draft, the old configured form answers - publish it (or ask the person to) under **Content → Forms**, then submit again and confirm the enquiry has the builder form's labels.

## 6. Commit and hand over

Commit on the branch, one form per commit where practical (`feat: convert the contact form to the form builder`). Tell the person: which forms were converted, which are drafts waiting to be published, which destinations were added to config and what they map, anything the scan could not see, and that the config definitions and old controller routes can be removed once they are happy - not before.
