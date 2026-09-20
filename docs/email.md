# Email

A client who does not want to set up a mail service still needs the site to
send: an enquiry notification, the reply to whoever filled the form in, an
invitation to the panel, a password reset. Gadya CMS sends those through
Gadya Media unless the site says otherwise.

## How it works

The site never holds a mail credential. It hands the finished message to
the Gadya Media portal over the link `gadya/connect` already keeps - signed
with the secret from pairing, stamped with the time, never accepted twice -
and the portal sends it on through Cloudflare.

```
Notification -> mailer `gadya` -> PortalTransport
    -> POST https://app.gadya.media/api/connect/v1/mail   (signed)
        -> Cloudflare Email Sending -> the recipient
```

That means a site's sending can be cut off, capped or watched from the
portal, and a server someone else can read holds nothing worth stealing.

## When it takes over

`mail.shared` is `auto` by default, which takes over only when both are
true:

- the site is **paired** with the portal (`php artisan gadya:connect`), and
- its mailer is still Laravel's `log` or `array` - nothing was set up -
  and the environment is not `local` or `testing`.

So a site with real mail details in `.env` is never touched. To opt out for
good, set `MAIL_MAILER` to the client's own service, or `GADYA_MAIL=false`.
To insist on Gadya's sender even though something else is configured, set
`GADYA_MAIL=true`.

## The address

Messages come from `{site}@on.gadya.media`, where `{site}` is the portal's
name for the site as a slug - `Acme Dental` becomes `acme-dental`. Name it
yourself with `GADYA_MAIL_MAILBOX`, or move the whole thing to another
domain with `GADYA_MAIL_DOMAIN`. **The portal has the last word**: it sends
from the address it holds for the site, so two clients can never end up
sharing a mailbox because they picked the same name.

Nothing reads that mailbox, so every message carries a **Reply-To** the
business will actually see: `mail.reply_to`, or `seo.organization.email`,
or - on a form notification - the address of whoever wrote in. A message
addressed from some other domain is rewritten to the shared address and its
original From becomes the Reply-To, which is where the answer was wanted
anyway.

## The footer

One quiet grey line at the foot of an HTML message, and one at the end of
the plain-text part:

> Email for this site is sent by [gadya.media](https://gadya.media)

Turn it off for a site with `GADYA_MAIL_FOOTER=false`. It is only ever
added to messages Gadya Media sent; a site on its own mail service never
carries it.

## Settings

| Key | Default | |
| --- | --- | --- |
| `mail.shared` | `auto` | `GADYA_MAIL` - `true`, `false` or `auto` |
| `mail.domain` | `on.gadya.media` | `GADYA_MAIL_DOMAIN` |
| `mail.mailbox` | the portal's name for the site | `GADYA_MAIL_MAILBOX` |
| `mail.reply_to` | `seo.organization.email` | `GADYA_MAIL_REPLY_TO` |
| `mail.footer` | `true` | `GADYA_MAIL_FOOTER` |
| `mail.max_attachment_megabytes` | `10` | |

## In the panel

**Settings → Automatic replies** says who sends the site's email and from
which address, and has **Send me a test email**, which sends to the signed-in
person's own address and reports the failure on the screen rather than in a
log. `gadya-cms:audit` carries the same check.

## Queues

Notifications are queued, so a portal that is briefly down means a retried
job rather than a lost enquiry. A refusal - a suspended site, an attachment
over the limit - fails the job with the portal's own words, which is what
the test button shows.

## What the portal must answer

For the portal side (`app.gadya.media`), signed exactly like every other
`gadya/connect` request:

`POST /api/connect/v1/mail`

```json
{
  "from": {"email": "acme-dental@on.gadya.media", "name": "Acme Dental"},
  "reply_to": [{"email": "hello@acmedental.test"}],
  "to": [{"email": "someone@example.test", "name": "Someone"}],
  "cc": [], "bcc": [],
  "subject": "New Contact enquiry from Someone",
  "html": "<!DOCTYPE html>…",
  "text": "…",
  "attachments": [
    {"filename": "menu.pdf", "content_type": "application/pdf", "content": "<base64>"}
  ]
}
```

`200` with any body means sent. Anything else is treated as a refusal and
the `message` field is shown to the client, so it should be written for
her: *"This site is suspended."*, *"That is more than we will send in an
hour."*

The portal should, on its side:

- **replace `from`** with the address it holds for the site that signed the
  request, keeping the display name. A site can then never send as another,
  whatever it asks for.
- **rate-limit per site**, so one compromised site cannot spend the domain's
  reputation.
- **log** every message it sends: site, to, subject, result.
- keep the Cloudflare account id and token in its own environment
  (`services.cloudflare.account_id`, `services.cloudflare.key` - see the
  [Cloudflare driver](https://laravel.com/docs/mail#cloudflare-driver)) and
  send with `symfony/http-client` installed.
