---
name: gadya-cms-forms
description: Convert a Gadya CMS site's existing forms - configured under forms.forms, hand-written <form> markup in Blade templates, or Livewire components that call StoreFormSubmission - into builder forms the client can edit under Content → Forms, then place them with <x-gadya-cms::form> or a Form section. Use when asked to convert, migrate or "make editable" a site's forms.
---

# Converting a site's forms to builder forms

## When to use this skill

Use it when asked to move a site's forms into the form builder, to make a form "editable by the client", or to replace hand-written form markup. The full reference is `references/forms.md` (also `vendor/gadya/cms/docs/forms.md`); read it first.

A builder form keeps the configured form's **slug and every field's name**, so the enquiries already in the inbox, the people told about new ones, the thank-you email and the analytics event all carry on. A built form is a **draft** until published, and the configured form keeps answering until then - converting breaks nothing.

## Rules

- **Branch first, never push.** Start from a clean tree (`git status --short` is empty): `git switch -c chore/convert-forms`. Pushing often deploys; leave that to the person.
- **Never delete a form's config definition** (`forms.forms.{name}` in `config/gadya-cms.php`) until the converted form is published and verified on the page. Removing it is a separate, later commit - and only when asked.
- **The commands never change templates.** You change the markup yourself, after reviewing what was built.
- Content is the client's: never run `migrate:fresh` or `db:seed`, never write to `gadyacms_*` tables by hand. Converting creates a draft through the command; publishing is a person's decision in the panel unless they asked you to publish.
- Follow the application's `.ai/rules`, CLAUDE.md or AGENTS.md. Run Pint on PHP you touch.

## 1. Scan

```bash
php artisan gadya-cms:forms:scan --json
```

Each entry has `kind` (`config`, `blade`, `livewire`), `name` (the package form it posts to, or null), `file` and `line`, the `fields` found (type, label, required, options, or the validation `rules`), the `config` definition if any, whether a `builder` form exists already, and a `suggested_slug`.

- A `blade` form with a `name` posts to the package (`route('gadya-cms.forms.store', 'name')` / `@cmsForm('name')`) and usually has a matching `config` entry: convert from config and read the labels from the template.
- A `blade` form with no `name` posts somewhere else - a controller of the site's own. Read that controller before converting: anything it does beyond storing and emailing (a booking API call, a payment) must not be lost. If it does more, stop and ask.
- A `livewire` form: read the component. Convert it only when it does nothing but validate and hand over to `StoreFormSubmission`.

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

Then check it for real: the page renders the form, and a test submission lands in **Content → Enquiries** under the same form name, with the notify emails sent. While the built form is still a draft, the old configured form answers - publish it (or ask the person to) under **Content → Forms**, then submit again and confirm the enquiry has the builder form's labels.

## 6. Commit and hand over

Commit on the branch, one form per commit where practical (`feat: convert the contact form to the form builder`). Tell the person: which forms were converted, which are drafts waiting to be published, anything the scan could not see, and that the config definitions can be removed once they are happy - not before.
