# Privacy choices

The New Jersey Data Privacy Act (in force since January 2025) lets a visitor opt out of the sale of her personal data and of targeted advertising, and requires a site to honour a universal opt-out signal - Global Privacy Control - as that opt-out. A site that loads Google Analytics, Google Ads, a Meta pixel or anything like them needs to ask, and to listen. The package gives it a banner, a way to reopen it, and a way to keep third-party tags waiting until the answer is yes.

Nothing changes on a site until the banner is switched on.

## Switching it on

**Settings → Privacy choices** (the `settings` ability):

- **Show the privacy banner** - off by default.
- **Honour Global Privacy Control** - on; leave it on.
- **Privacy policy address** - `/privacy-policy`, or a full `https://` address.
- **What it says** - every word on the banner: the heading, the message, the *Accept all*, *Reject all*, *Choose* and *Save my choices* buttons, the line above the choices, the privacy policy link, the link that reopens the banner, and the note shown to a browser sending Global Privacy Control.
- **The choices** - the name and the description of each category.

Each box shows its plain-English default; leave it empty to keep that. Like every other word on the site it is saved to the draft (the `privacy` key of the site document, with only what the client actually wrote), goes live with **Publish changes**, and is kept in the revision history. The defaults go through Laravel's translator (`__('Accept all')`), so a site with `lang/es.json` has Spanish defaults, and a second-language copy of the `privacy` key can sit beside the first. For someone with the live editor on, the banner carries a link to this screen: it is not edited in place, because every button on it does something when clicked.

`gadya-cms.privacy` holds only the switches' defaults (`banner_enabled`, `honour_gpc`, `policy_url`) and the cookie's name and lifetime.

## In the layout

```blade
<footer>
    ...
    <x-gadya-cms::privacy-choices-link />
</footer>

<x-gadya-cms::consent-banner />
@cmsToolbar
</body>
```

- **`<x-gadya-cms::consent-banner />`** - three equal buttons: *Accept all*, *Reject all*, *Choose*. *Choose* opens three categories: **necessary** (always on), **analytics** and **marketing**. It sits at the foot of the screen without covering the page (it is a labelled region, not a modal), every control is a real button or checkbox in reading order, focus is visible, and Escape closes it once a choice exists. Its light styles live under `:where(.cms-consent)` so any rule the site writes wins; colours follow `--cms-consent-bg`, `--cms-consent-ink`, `--cms-consent-accent` and `--cms-consent-accent-ink`.
- **`<x-gadya-cms::privacy-choices-link />`** - "Your privacy choices", or whatever the client wrote; reopens the banner, with the visitor's current choice ticked, and moves focus into it. With the banner off it is a plain link to the policy, or nothing when there is none.

## Keeping tags waiting

Wrap every third-party tag:

```blade
<x-gadya-cms::consented-script category="analytics" src="https://www.googletagmanager.com/gtag/js?id=G-XXXX" async />
<x-gadya-cms::consented-script category="analytics">
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-XXXX');
</x-gadya-cms::consented-script>

<x-gadya-cms::consented-script category="marketing">
    !function(f,b,e,v,n,t,s){...}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '1234567890'); fbq('track', 'PageView');
</x-gadya-cms::consented-script>
```

With the banner on, each is written as `<script type="text/plain" data-cms-consent="marketing">`, which no browser runs. A small inline runtime (included once, whichever component comes first) reads the visitor's choice and turns each allowed tag into a real script - straight away for someone who already said yes, or the moment she does. Every other attribute (`async`, `id`, a `type="module"`) is carried across; a CSP nonce from `Vite::useCspNonce()` is added to the tag and the runtime. Taking a yes back reloads the page, because a tag that is already running cannot be unloaded.

`category="necessary"` renders an ordinary script. With the banner off, `analytics` tags run as they always did; `marketing` tags still wait for the runtime so a Global Privacy Control signal is honoured even then.

In JavaScript: `window.gadyaConsent.allowed('marketing')`, `.read()`, `.open()`, and a `gadya:consent` event on `document` with the new choice.

## Global Privacy Control

A browser that sends `Sec-GPC: 1` or sets `navigator.globalPrivacyControl` has said no to marketing - the sale of personal data and targeted advertising - whatever else it clicks. The marketing checkbox is shown off and disabled with a line saying why, *Accept all* accepts analytics only, and marketing tags never run. Server-side, `Consent::allows('marketing', $request)` is false for the header. The signal says nothing about counting visits, so analytics is still the visitor's choice.

## The cookie

One first-party cookie, `gadya_consent` (`privacy.cookie`), for `privacy.cookie_days` (365): `{"v":1,"necessary":true,"analytics":false,"marketing":false,"gpc":false,"at":"2026-..."}`, URL-encoded, `SameSite=Lax`, `Secure` on https. The browser writes it, so a page served from a cache still honours it, and the package excuses it from Laravel's cookie encryption so the server can read it too.

```php
$consent = app(\Gadya\Cms\Privacy\Consent::class);

$consent->allows('analytics', $request);   // may this run for this visitor?
$consent->refuses('analytics', $request);  // has she actively said no?
$consent->choice($request);                // ['necessary' => true, 'analytics' => ..., 'marketing' => ..., 'gpc' => ..., 'at' => ...] or null
```

## The CMS's own analytics

The dashboard's figures are counted on the site's own server ([Analytics](analytics.md)):

- **No cookie is set and nothing is stored in the browser.** The beacon script stores nothing either.
- **No IP address is stored.** A visitor is an HMAC of IP address, browser and today's date, keyed with the application key, so the same person has a different identifier every day and cannot be followed from one day to the next. It is not a browser fingerprint in the tracking sense - nothing about the device is collected beyond the user-agent string the request already carried - but it is a pseudonymous identifier for that day.
- **Campaign parameters** (`utm_*`) are kept in Laravel's own session for the visit - the session cookie the site sets anyway, which is strictly necessary.
- **Nothing is shared** with anyone, sold, or used for advertising.

That is why the package does not wait for consent before counting. But once a visitor **refuses analytics** in the banner, the package stops counting her: her page views are not recorded at all, and a phone tap, an enquiry or a search she makes is counted as nobody in particular - a fresh random value each time in place of the daily identifier - so it cannot be linked to anything else she does. Accepting analytics, or never answering, leaves counting as it was.

## The portal and the audit

The check-in carries `consent: {banner_enabled, honours_gpc}`.

`gadya-cms:audit` looks through the site's templates for the snippets of Google Analytics and Tag Manager, Google Ads, the Meta, TikTok, LinkedIn, Pinterest and Snap pixels, Microsoft Clarity and Bing, and Hotjar. If it finds one while the banner is off, the banner component is missing, or no tag is wrapped in `consented-script`, it says so and what to do.
