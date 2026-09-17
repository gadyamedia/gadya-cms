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

Scores (performance, accessibility, best practices, SEO), LCP, CLS and the five biggest opportunities are kept per check, so a page's history can be read back. Schedule it weekly rather than daily; the quota is not large.

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
