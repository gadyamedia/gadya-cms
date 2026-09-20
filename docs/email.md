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
which address, lists what has gone out lately and whether it arrived, and
has **Send me a test email**, which sends to the signed-in person's own
address and reports the failure on the screen rather than in a log.

The address and the list come from the portal, asked for when that screen
is opened and remembered for ten minutes. The site could work its own
address out from its name, but the portal is what decides it - two clients
called the same thing cannot share a mailbox - so the panel asks rather
than assumes. Sending never waits on that question: a message on its way
uses what was last cached, and the portal stamps the real address on it
anyway.

**Where replies go** is a field on the same screen, kept with the site's
other settings rather than in a config file. Blank falls back to
`seo.organization.email`.

`gadya-cms:audit` checks both that the site can send at all and that a real
queue carries it.

## Queues

Notifications are queued, so a portal that is briefly down means a retried
job rather than a lost enquiry. A refusal - a suspended site, an attachment
over the limit - fails the job with the portal's own words, which is what
the test button shows.

**Give the site a real queue.** With `QUEUE_CONNECTION=sync` the call to
the portal happens inside the visitor's own form submission, and she waits
for it. The wait is capped at `mail.timeout` (8 seconds) rather than the
check-in's fifteen, but a worker is the answer; the audit asks for one.

## What the portal does with it

The portal end lives in `app.gadya.media` (`SendSiteMailController`,
`App\Services\Sites\SiteMailer`). Signed exactly like every other
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

`200` with `{id, from, sent_at}` means sent. Anything else is a refusal,
and its `message` is written for the client to read:

| | |
| --- | --- |
| `403` | Sending is switched off for this site, from its page in the portal. |
| `413` | Over 14MB of message. The site stops at 10MB of attachments before it gets here. |
| `422` | Nothing in the message, too many recipients, or a payload that will not validate. |
| `429` | Over 100 messages in the hour. |
| `502` | Cloudflare would not take it. The site's queue retries. |

On its side the portal:

- **decides the From address itself**, from the pairing that signed the
  request. A site cannot send as another however it fills in `from`; the
  display name is the only part it chooses. The address is allocated when
  the site pairs - the pairing response now carries it as `mail_address` -
  is unique across sites, and does not move if the site is renamed. The
  domain it sits on is a portal setting, so moving every site to another
  sending domain is one field, not a release.
- **caps each site at 100 messages an hour**, so one compromised site
  cannot spend the domain's reputation before anyone notices.
- **logs every message** - site, from, to, subject, result, size - on the
  site's **Email** tab, where a refusal is recorded too and sending can be
  switched off in one click.
- **strips newlines from every header** it is given, so a subject or a
  recipient name cannot smuggle in a `Bcc:`.
- keeps the Cloudflare account id, token and sending domain to itself. They
  are set on the portal's **Integrations** screen, where the token is stored
  encrypted and never shown again, and fall back to `CLOUDFLARE_ACCOUNT_ID`,
  `CLOUDFLARE_KEY` and `SITE_MAIL_DOMAIN` in its environment. The domain has
  to be verified for [Cloudflare Email Sending](https://laravel.com/docs/mail#cloudflare-driver),
  SPF, DKIM and DMARC included, before anything it sends is delivered.
