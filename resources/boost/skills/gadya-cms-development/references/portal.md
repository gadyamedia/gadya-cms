# The Gadya portal

A site paired with the Gadya Media portal through `gadya/connect` (`php artisan gadya:connect <code>`) does more than check in. Everything below is off on a site that is not paired, and none of it can break a page or a form if the portal is down.

## Enquiries, the moment they arrive

Every form submission is sent to the portal (`POST /api/connect/v1/submissions`) as soon as it is saved, on the queue, so an enquiry nobody answers is chased within the hour rather than at the next check-in. The visitor has already been thanked by then: a portal that is slow, down or refusing never reaches her.

- Opening an enquiry in **Enquiries**, or marking it answered, tells the portal too (`POST /submissions/{id}/status`), so it stops chasing one somebody has dealt with.
- `gadya-cms:push-submissions` sends anything from the last seven days the queue missed. It is scheduled every five minutes on the site's own scheduler; nothing needs adding to `routes/console.php`.
- `pushed_at` on `gadyacms_form_submissions` says when the portal had it. A refusal that will never change (a 422) is logged and written off rather than retried every five minutes for a week; the enquiry is still in the inbox.
- Switch it off with `GADYA_CMS_PUSH_SUBMISSIONS=false` (`portal.push_submissions`).

The check-in still carries `leads` - `{count, oldest_hours}` of unopened enquiries - for a portal that has no submissions yet - and `forms`: `{count, submissions_last_30_days}`, the live forms (built and configured) and the enquiries that came through them this month.

A built form marked as a call-back form sends a ticked consent question to the portal as `callback_requested`, with its exact wording, like the button below. A built form's name, email, phone and message are found by the kind of question, whatever it is called.

## What the portal can ask the site to do

The portal can queue commands for a site; `gadya/connect` fetches them every minute, checks each is signed by the portal and not expired, and runs the handler that answers to its type. This package adds four, tagged `gadya-connect.remote-commands`:

| Type | Does |
| --- | --- |
| `coming_soon.on` | Closes the site to visitors, as **Settings → Coming soon mode** does. Optional `heading`, `message`, `until` (ISO date) and `password`; anything not sent keeps what the client wrote. |
| `coming_soon.off` | Opens it again. |
| `backup.run` | Runs the site's own `backup:run` from spatie/laravel-backup (`only_db` for the database alone). Fails, saying why, on a site without it. |
| `content.request` | Drafts a change the client asked for - below. |

A handler is a plain class with `type(): string` and `handle(array $payload): array` returning `['output' => string, 'result' => array|null]`, and throws to fail. A site can add its own the same way:

```php
$this->app->tag([RestartTheBookingSync::class], 'gadya-connect.remote-commands');
```

`upgrade.finish`, from gadya/connect itself, finishes an upgrade on the live site after the deploy - `php artisan gadya:upgrade`'s server phase, below in [Upgrading](upgrading.md).

## Changes the client asks for

"Please change the opening times on the contact page", asked in the portal, arrives as `content.request` with the request's text and, optionally, the page's address. The site's AI - the client's own key when she has one under **Settings → AI**, Gadya's through the portal otherwise - reads the words on that page (or on every page that is not archived, and picks one) and proposes new wording for only the fields that need it. It may only touch fields the live editor may write (`editable_fields`), never photos.

The wording goes into the **draft** - the same draft the client edits by hand - and never onto the live site. Everyone who can edit pages is told in the panel's notification bell, with a preview link, and the change is listed under **Content → Requested changes**:

- **Publish** as usual and the request is marked *live*.
- **Discard** puts each field back as it was, unless someone has rewritten it since - theirs is kept.
- Undo it by hand before publishing and it is reported as discarded.

The portal hears which through the check-in's `change_requests` section: every request still waiting, and those settled in the last seven days. A request the AI cannot do by changing words - it needs a new page, a photo, a developer - fails the command, with the AI's one-sentence reason shown in the portal. The same request delivered twice is drafted once.

The bell needs Laravel's `notifications` table (`php artisan make:notifications-table && php artisan migrate`); the panel turns it on by itself when the table exists.

In tests, fake the agent like any other:

```php
use Gadya\Cms\Ai\Agents\ChangeWriter;

ChangeWriter::fake([['page' => 'about', 'changes' => [['field' => 'heading', 'value' => 'Meet the team']], 'reason' => 'Changed the heading.']]);
```

## Speak with our team

```blade
<x-gadya-cms::call-back />
<x-gadya-cms::call-back button="Ring me back" heading="We will call you" intro="Leave your number." />
```

A button that opens a small dialog asking for a name, a phone number and, optionally, what it is about, with an **unticked** box beside the consent sentence. It is a native `<dialog>`: the page behind is inert, Escape and Cancel close it, Tab stays inside, focus starts on the first field (or the first with an error) and goes back to the button on close. Without JavaScript the form shows in the page.

It posts to the built-in `callback` form, through the same pipeline, honeypot and rate limit as every other form. A site that lists its own `callback` under `forms.forms` replaces it. The visitor's consent is kept on the submission - the exact sentence she saw, when, and her IP address - and sent to the portal with `callback_requested: true`, where an AI receptionist may ring her back. Nobody is rung without the box ticked.

Who is emailed: the addresses in `portal.callback.notify`, or else whoever is told about the contact form.

```php
'portal' => [
    'callback' => [
        'label' => 'Call back',
        'button' => 'Speak with our team',
        'heading' => 'We will call you back',
        'intro' => 'Leave your number and we will ring you as soon as we can.',
        'consent_text' => 'I agree to {{ business }} calling me back about my enquiry on the number above. The call may be made by an automated AI assistant, and I can ask not to be called again at any time.',
        'success' => 'Thank you. We will call you shortly.',
        'notify' => [],
    ],
],
```

Change `consent_text` with care: it is the record of what the visitor agreed to. `{{ business }}` becomes `brand.name`. The component brings no styles; its classes start `cms-callback__`. A page that sets a CSP nonce through Vite has it on the component's script.

## Google reviews

```blade
<x-gadya-cms::reviews />
<x-gadya-cms::reviews :limit="3" :min-rating="5" heading="Kind words" link-text="Tell us how we did" />
```

The business's Google reviews, from the portal (`GET /api/connect/v1/reviews`), which asks Google so no Places key lives on the site. Asked at most every twelve hours (`portal.reviews.cache_hours`; a failed ask is retried after half an hour), showing those at or above `portal.reviews.min_rating` (4) up to `portal.reviews.limit` (6), with the overall rating, **Reviews from Google**, and a **Leave us a review** link when the portal has one. A review is always shown as text, never markup.

It renders nothing at all when the site is not paired, the portal does not answer, or there is nothing to show, so a template can place it unconditionally. There is deliberately no `AggregateRating` markup: Google ignores review stars a business publishes about itself. Classes start `cms-reviews__`.
