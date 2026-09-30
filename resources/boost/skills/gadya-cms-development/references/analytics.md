# Analytics

The dashboard shows where visitors came from and what they did, counted on your own server from your own traffic. There is no third-party script, so there is no cookie banner to earn.

- **No cookie is set and no address is stored.** A visitor is an HMAC of address, user agent and date, so the same person gets a fresh identifier every day. Counting people once a day works; following anyone beyond it does not.
- **Country and town come from the CDN's request headers** (Cloudflare's `CF-IPCountry`, `cf-region`, `cf-ipcity`) where one sits in front of the site. Nothing is looked up.
- **Editors and bots are not counted**, nor are the panel, the editor, and anything in `gadya-cms.analytics.skip_prefixes`. See *What is counted* below.
- Anything older than `retention_days` is pruned by `gadya-cms:prune-analytics`.
- **A visitor who refuses analytics in the privacy banner is not counted**: her page views are not recorded, and anything else she does is counted without the daily identifier. See *The CMS's own analytics* in [Privacy choices](privacy.md) for exactly what is and is not stored.

## What is counted

Counted: a page a person opened in a browser, once per load, unless they refused analytics. Not counted: signed-in editors, the panel and editor, JSON requests, configured skip prefixes, and anything that looks like a machine.

**Bots.** `Gadya\Cms\Analytics\BotDetector` matches the user agent, case-insensitively, against built-in lists: generic words (`bot`, `crawl`, `spider`, `monitor`, `check`, `preview`, `headless`, `lighthouse`, `curl`, `wget`, `postman` and the like), uptime and monitoring services, HTTP libraries (`python-requests`, `go-http-client`, `okhttp`, `axios`, `guzzle` and the like), link-preview and social fetchers, AI and SEO crawlers (GPTBot, ClaudeBot, Perplexity, Semrush, Ahrefs, Applebot and the like), and Gadya's own checks. A request with no user agent is a bot, and so is one that says `Purpose: prefetch|prerender` or `Sec-Purpose: prefetch`, because nobody has opened that page yet. Add your own with `gadya-cms.analytics.bot_patterns`, a list of regex fragments:

```php
'bot_patterns' => ['acmewatcher', 'internal-tool/'],
```

**Visitors' addresses behind Cloudflare.** Behind Cloudflare the address Laravel sees is a Cloudflare server, shared by everyone who reaches that location. The CMS therefore reads `CF-Connecting-IP`, but only when the request's direct peer is inside Cloudflare's published ranges (or ranges you list), so a forged header sent straight to your server is ignored. This one address (`Gadya\Cms\Support\ClientIp`) keys the daily visitor identifier, the form rate limit, the consent record and the address kept with a submission (still full, masked or none, as configured). A site that configures Laravel's trusted proxies, or sits behind no proxy, is already right and unaffected.

```php
'client_ip' => [
    'trust_cloudflare' => true,          // false turns it off
    'extra_trusted_ranges' => [],        // other proxies whose CF-Connecting-IP-style header you trust
],
```

Cloudflare's ranges are embedded in `ClientIp::CLOUDFLARE_RANGES` (from cloudflare.com/ips-v4 and ips-v6, 2026-09-30) and change rarely; a new range is picked up by updating the package, or list it in `extra_trusted_ranges`. `gadya-cms:audit` has an optional check that visitors' real addresses reach the site.

**Cleaning up past inflated views.** Before the bot list was wider, a monitor could log hundreds of homepage views a day as one "visitor", including the views the portal made before its user agent was renamed. Find them with:

```bash
php artisan gadya-cms:analytics:clean-monitors            # dry run: prints what would go
php artisan gadya-cms:analytics:clean-monitors --force    # removes it
```

A visitor-day (one site, one business day in the [site's time zone](operations.md#the-sites-time-zone), one identifier) counts as a monitor only when it has at least `--min-views` (default 200) views, at least 95% of them of the same path, and nothing marks it as a person: no campaign parameters and at most one referrer. Many different visitors on a busy page, or one person reloading thirty times, never match. Other options: `--days=` (how far back, default everything retained) and `--path=`. Deletion is chunked and runs in a transaction per visitor-day.

## Events

```blade
<a href="#book" data-analytics="booking_start" data-analytics-label="Brooklyn">Check availability</a>
```

Only names in `gadya-cms.analytics.events` are accepted. Built forms count their own views, starts, steps and sends too - see *Figures* in [Forms](forms.md). Telephone links count themselves as `phone_click`; form submissions count as whatever their `analytics_event` says. Campaign parameters (`utm_source`, `utm_medium`, `utm_campaign`) are remembered for the session, so a lead three pages later is still credited to the campaign that brought them.

## Live

With a broadcaster configured (`composer require laravel/reverb && php artisan reverb:install`) the live panel updates the moment someone lands. The package reads Laravel's broadcasting configuration and points Filament's Echo client at it; the channel is private and authorised by the package. Without one the panel polls.

## Reports

Every range starts at the business's midnight in the [site's time zone](operations.md#the-sites-time-zone): "today" is the business's today, the chart groups visits by the business's day, and the CSV gives each visit's date and time there.

- **Download CSV** - every visit in the range, with day, page, referrer, campaign, device and place. No visitor identifier.
- **Email me this** - the summary, now, to the signed-in person.
- **Weekly email** - a list of addresses that get the last seven days every Monday, sent by `gadya-cms:analytics-digest`. Schedule it:

```php
Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00')->timezone(\Gadya\Cms\Support\SiteTimezone::current());
```

## The map

Point `gadya-cms.analytics.world_map` at a world map SVG whose paths carry lowercase ISO country codes as classes and the dashboard draws a choropleth. Leave it null and the figures render as a list.

## Reading the numbers yourself

```php
$report = app(\Gadya\Cms\Analytics\AnalyticsReport::class)->for(30);
$report->headline();   // views, visitors, phone_clicks, enquiries, contact_rate
$report->daily();      // one row per day, quiet days included
$report->topPages();
$report->referrers();
$report->campaigns();
$report->events();
$report->devices();
$report->live();       // the last few minutes
```

## Search numbers from Google

The figures above are counted on the site. What people searched for on Google before they arrived is not something the site can see, so the dashboard's **Search** section asks Google Search Console through the Gadya Media portal. A client connects it with one button; see [Google Search Console](search-and-readiness.md#google-search-console).
