# Analytics

The dashboard shows where visitors came from and what they did, counted on your own server from your own traffic. There is no third-party script, so there is no cookie banner to earn.

- **No cookie is set and no address is stored.** A visitor is an HMAC of address, user agent and date, so the same person gets a fresh identifier every day. Counting people once a day works; following anyone beyond it does not.
- **Country and town come from the CDN's request headers** (Cloudflare's `CF-IPCountry`, `cf-region`, `cf-ipcity`) where one sits in front of the site. Nothing is looked up.
- **Editors and bots are not counted**, nor are the panel, the editor, and anything in `gadya-cms.analytics.skip_prefixes`.
- Anything older than `retention_days` is pruned by `gadya-cms:prune-analytics`.

## Events

```blade
<a href="#book" data-analytics="booking_start" data-analytics-label="Brooklyn">Check availability</a>
```

Only names in `gadya-cms.analytics.events` are accepted. Telephone links count themselves as `phone_click`; form submissions count as whatever their `analytics_event` says. Campaign parameters (`utm_source`, `utm_medium`, `utm_campaign`) are remembered for the session, so a lead three pages later is still credited to the campaign that brought them.

## Live

With a broadcaster configured (`composer require laravel/reverb && php artisan reverb:install`) the live panel updates the moment someone lands. The package reads Laravel's broadcasting configuration and points Filament's Echo client at it; the channel is private and authorised by the package. Without one the panel polls.

## Reports

- **Download CSV** - every visit in the range, with day, page, referrer, campaign, device and place. No visitor identifier.
- **Email me this** - the summary, now, to the signed-in person.
- **Weekly email** - a list of addresses that get the last seven days every Monday, sent by `gadya-cms:analytics-digest`. Schedule it:

```php
Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00');
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
