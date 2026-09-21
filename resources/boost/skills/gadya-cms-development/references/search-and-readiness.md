# Search Console, page speed and AI readiness

**Settings → Search & speed** connects two Google services; the dashboard shows all three cards below once the plugin has `search()` on (the default).

## Google Search Console

What people typed into Google to find the site, and the pages they landed on.

1. In Google Cloud, create a service account and download its JSON key.
2. In Search Console, add the service account's email as a user (Full or Restricted) on the property.
3. Paste the property exactly as Search Console shows it (`sc-domain:example.com` or `https://www.example.com/`) and the whole JSON key. The key is stored encrypted.
4. Press **Fetch from Google now**, and schedule the nightly fetch:

```php
Schedule::command('gadya-cms:search-console')->dailyAt('05:00');
```

Google's data lags by two days, so the 28-day window ends the day before yesterday. Snapshots live in `gadyacms_search_snapshots`; `SearchConsole::topQueries()`, `topPages()` and `totals()` read them.

## Page speed

Lighthouse, run on Google's machines against the live site through the PageSpeed Insights API. It works without a key at a low rate; a free key from Google Cloud lifts the quota.

```bash
php artisan gadya-cms:pagespeed                       # the first five pages of the sitemap, on a phone
php artisan gadya-cms:pagespeed --url=https://example.com/pricing --strategy=desktop
```

Scores (performance, accessibility, best practices, SEO), LCP, CLS, the five biggest opportunities and every failing audit - with the elements that failed it - are kept per check, so a page's history can be read back. Schedule it weekly rather than daily; the quota is not large.

## Speed & accessibility

**Settings → Speed &amp; accessibility** turns the last check into something the client can act on. Each failure is written in plain English, against the page it was found on, and sorted into two piles:

- **Things the CMS owns** - a photo with no description, a page with nothing to show under its name in search results. Two buttons at the top of the screen put them right: Gadya looks at each photo and writes one sentence saying what is in it, and reads each page and writes its search snippet. Everything lands in the **draft**, so the client reads it and presses Publish; nothing reaches visitors on its own.
- **Things the templates own** - an ARIA attribute where it is not allowed, a heading that jumps a level. These are named, with the failing selector, and left for whoever looks after the code. `Failures::brief()` writes the same thing out for a developer or an agent.

```bash
php artisan gadya-cms:fix                 # describe the photos, write the missing snippets, list what is left
php artisan gadya-cms:fix --photos --limit=10
```

The writing is done with the site's own AI key when she has one under **Settings → AI**. When she has not, and the site is paired with the portal, it is done by **Gadya Media's key**: nothing to buy, nothing to configure, and no key on the client's server. The portal counts what each site asks for and can stop a site on its own.

Every fix is written down in `gadyacms_fixes` and listed at the foot of the screen - what was changed, what it now says, and whether Gadya or her own key wrote it - so the client is told rather than finding her words quietly rewritten.

## Ready for AI assistants

`gadya-cms:agent-ready` scores the site out of 100 against what search engines and AI assistants look for, and names the fix for anything missing. The same score sits on the dashboard. The checks:

- `robots.txt` and `sitemap.xml` served and linked (`seo.robots`, `seo.sitemap`)
- `/llms.txt` describing the site, its pages and articles (`seo.llms`)
- AI crawlers named in `robots.txt` - welcomed or refused - rather than left to the default (`seo.ai_crawlers`)
- every page and article readable as Markdown with `Accept: text/markdown` (`seo.markdown`)
- every visible page with a description; most with a written search snippet
- Organisation and WebSite JSON-LD on every page (`seo.site_name`, `seo.organization`), Article and FAQPage on articles
- canonical and Open Graph tags on every page (`@cmsSeo`)
- at least one published article

`--live` also fetches `robots.txt`, `sitemap.xml` and `llms.txt` from `APP_URL` and reports their status.

```php
'seo' => [
    'llms' => true,
    'markdown' => true,
    'ai_crawlers' => [
        'allow' => ['GPTBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended', 'CCBot'],
        'block' => ['Bytespider'],
    ],
    'organization' => [
        'type' => 'LocalBusiness',
        'telephone' => '+1 555 010 2030',
        'area' => 'Springfield',
        'same_as' => ['https://instagram.com/springfieldparties'],
    ],
],
```

Markdown for pages is answered by middleware from the site document (heading, description, sections); articles convert their HTML. A request whose `Accept` header puts `text/html` first still gets the page.

## Get found

**Settings → Get found** is the checklist for everything outside the code that decides whether Google and AI assistants find the site:

- **Can they reach it?** `robots.txt`, `sitemap.xml` and `llms.txt` fetched live from `APP_URL`, the way a crawler would, cached ten minutes (**Check again** clears it). A `/robots.txt` that answers 404 is almost always the web server: Laravel Forge's default nginx config has `location = /robots.txt { access_log off; log_not_found off; }`, which looks for a static file and, finding none, hands the request to Laravel *keeping the 404*. Crawlers ignore a robots file served as 404. Delete that line (Forge: the site → Edit Files → Edit Nginx Configuration). Laravel Herd does the same locally.
- **The sitemap address** with a copy button, and the steps to add it to Google Search Console (Domain property, TXT verification, Sitemaps → Submit) and Bing Webmaster Tools (import from Google).
- **DNS for every domain** in `seo.domains`, looked up live over DNS-over-HTTPS (Cloudflare, then Google; four-second timeout, cached ten minutes). The first domain is the site; the others should point at the same server and redirect. Each record says what it is for and whether it is *in place*, *missing*, *pointing elsewhere* or *optional*:

| Domain | Record | Value |
| --- | --- | --- |
| main | `A @` | the server's IP (read from the main domain) |
| main | `CNAME www` | the domain |
| main | `TXT @` | `google-site-verification=…` from Search Console |
| main, when `mail.from.address` is on it | `TXT @` | `v=spf1 include:… ~all` (Postmark, Mailgun, SES and Resend known) |
| main, likewise | `TXT _dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@domain` |
| main | `CAA @` | optional: `0 issue "letsencrypt.org"` |
| others | `A @`, `CNAME www` | the same server, then a redirect on it |
| others | `TXT @` | optional: `v=spf1 -all` when no mail is sent from it |

The page names the DNS host from the nameservers (GoDaddy, Cloudflare, Route 53, Namecheap…) and warns when a domain sits on a for-sale parking service such as Afternic or Sedo. Local addresses (`.test`, IPs) are never looked up.

```php
'seo' => [
    'domains' => ['springfieldparties.com', 'springfield-parties.co.uk'],

    // robots.txt: Content-Signal for every group (contentsignals.org). [] to leave out.
    'content_signals' => ['search' => 'yes', 'ai-input' => 'yes', 'ai-train' => 'no'],

    // Link: <…/llms.txt>; rel="describedby", <…/sitemap.xml>; rel="sitemap" on the home page.
    'link_headers' => true,
],
```

Checkers such as isitagentready.com also look for agent DNS records (DNS-AID `_agents` SVCB), MCP server cards, OAuth metadata and payment protocols. Those describe a site that runs its own agent or API; a business site rightly has none, and the page says so rather than inviting empty records.
