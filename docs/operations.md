# Running a site

## The trash

Deleting a page takes it off the site at once and puts it in the trash, where it can be restored for thirty days. The **Trash** filter on the pages list shows what is in there; **Restore** puts a page back, and a page that was deleted *and* published gets its draft back from its published copy, so it comes back editable rather than live-but-unreachable.

An address a trashed page still holds can be taken again: the row is restored and overwritten, because asking for that address again is how somebody recreates a page they deleted.

```bash
php artisan gadya-cms:prune-trash          # empties what nobody came back for
php artisan gadya-cms:prune-trash --days=7
```

Articles use the same trash. `gadya-cms.trash.keep_days` sets the window.

## Duplicating

Every page and every article has **Duplicate**. A duplicated page is hidden and takes a new address; a duplicated article is a draft with `(copy)` on its title and the same categories and tags. It is the fastest way to write the second of anything.

## Who changed what

**Settings → Activity** answers every question that begins "who deleted the…": each change to a page, article, photo, event, category, redirect or subscriber, with who made it, when, and which fields changed. Publishing, scheduling, reverting and turning coming-soon mode on and off are recorded too.

```php
'activity' => ['enabled' => true, 'keep_days' => 180],
```

```bash
php artisan gadya-cms:prune-activity
```

Record something of your own:

```php
app(\Gadya\Cms\Activity\Activity::class)->record('invoice.sent', $invoice->number);
```

## Publishing at a time

**Publish changes** asks *when*, in the [site's time zone](#the-sites-time-zone). Blank means now; a time in the future holds the whole draft until then, and the dashboard says so until it goes out. Publishing again changes or cancels it.

```php
Schedule::command('gadya-cms:publish-due')->everyFiveMinutes();
```

Without that command scheduled, nothing goes out on its own - so schedule it before offering the feature to a client.

## Coming soon

**Settings → Coming soon mode** closes the public site without closing the panel: visitors see a short notice, and anyone who can sign in still sees the site as normal, so work carries on. Give it a password and the screen shows a link that carries it, for whoever needs a look before it opens. Name a time and the site opens itself.

The panel, the live editor, `storage` and the build assets are never covered; add more with `gadya-cms.maintenance.allow_prefixes`.

## Broken links

**Settings → Broken links** collects addresses that do not work, from both ends:

- **Someone went there** - a visitor asked for it and got a 404, with how often and where they came from. Requests for files and for the panel's own paths are ignored, so the list is only addresses worth fixing.
- **Linked from the site** - `gadya-cms:check-links` walked the published pages and articles and found the site linking to something that is gone.

Either way the fix is the same and is one click away: **Send it somewhere** writes a redirect and marks the link fixed.

```bash
php artisan gadya-cms:check-links              # internal links, costs nothing
php artisan gadya-cms:check-links --external   # also asks other people's servers
```

```php
Schedule::command('gadya-cms:check-links')->weekly();
```

## Automatic replies

**Settings → Enquiry emails** is the "thank you, we have your message" email, per form, in the client's own words. `{{ name }}`, `{{ business }}` and the name of any field on the form are replaced; a blank line starts a new paragraph. It is only sent when the form collected an email address to send it to.

## The site's time zone

Sites run on UTC (`app.timezone`) and so does every stored timestamp. The business does not: a client's admin showed "30 Sep, 7:57am" for something done at 3:57am in New Jersey, and an enquiry email said "1:21am" for one made at 9:21pm the evening before. So the CMS has its own time zone, used for **display, typed input and "what day is it"** - never for storage.

**Appearance → Locations → Time zone** is a searchable list (the common US zones first, then the rest by region) with **Use this device's time zone** beside it. It is the `site.timezone` option; `GADYA_TIMEZONE` (config `gadya-cms.timezone`) is the default for a site that has not chosen, and with neither the application's own zone applies, so **nothing changes for a site until it chooses one**. A value that is not a real zone (`DateTimeZone::listIdentifiers()`) is skipped.

```php
use Gadya\Cms\Support\SiteTimezone;

$zone = app(SiteTimezone::class);

$zone->name();                              // "America/New_York"
$zone->now();                               // CarbonImmutable, in the site's zone
$zone->local($post->published_at);          // a stored moment, as the business sees it
$zone->toStorage('2026-10-05 09:00');       // typed on the business's wall clock -> UTC
$zone->startOfLocalDay() / endOfLocalDay(); // the business's day, as UTC moments for a query
$zone->format($submission->created_at, 'D j M Y, g:ia');
```

What uses it:

- **The panel.** Filament's own display zone (`FilamentTimezone`) is set from it, so every date column, entry and picker shows local time and a picker converts back to UTC on save - including an article's publish date, a page's "show from", and **Publish changes** at a time. An application that calls `FilamentTimezone::set()` itself keeps its own.
- **Emails, texts and exports.** "Sent from /contact on Tue 29 Sep 2026, 9:21pm", the dates in the takeout and the CSV files, the coming-soon "Back Friday at 9am".
- **The public site.** Article and comment dates, the accessibility statement, the opening-hours badge (see [Menus and opening hours](local-business.md)).
- **Days.** "Today", "yesterday" and last 7 or 30 days in the analytics and the form stats start at the business's midnight, and the per-day chart groups by the business's day. It groups in PHP after fetching the window, so it is right on SQLite and MySQL and across the nights the clocks change; the day is 23 or 25 hours long then, and a test covers both. Visitors are counted once per business day.
- **Schedules.** Give a job with a time of day the business's clock: `->dailyAt('05:00')->timezone(\Gadya\Cms\Support\SiteTimezone::current())` (`gadya-cms:audit` prints the lines).
- **The portal** is told the zone at check-in (`timezone`).

What is deliberately left alone:

- **Stored timestamps, `config('app.timezone')` and PHP's default zone.** Existing rows are UTC; mixing would corrupt them.
- **Event start and end times** are what the business typed for its own clock ("6pm"), kept exactly as typed, so they never shift when the zone is changed. They are compared with the business's clock and turned into real moments only for the calendar file and structured data.
- **Dates with no time** (a follow-up day, a holiday) are calendar days, not moments.
- Machine-readable instants (sitemap `lastmod`, ISO timestamps to the portal) stay moments with their own offset; Search Console reports in Google's own day.

Choosing a zone changes how dates already typed are read once: a publish time a client typed as "9am" before choosing was stored as 09:00 UTC, and is now shown as 5am in New York. Check anything scheduled when a site first sets its zone.

## Everything to schedule

```php
use Gadya\Cms\Support\SiteTimezone;

Schedule::command('gadya-cms:publish-due')->everyFiveMinutes();
Schedule::command('gadya-cms:search-console')->dailyAt('05:00')->timezone(SiteTimezone::current());
Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00')->timezone(SiteTimezone::current());
Schedule::command('gadya-cms:check-links')->weeklyOn(2, '03:00')->timezone(SiteTimezone::current());
Schedule::command('gadya-cms:pagespeed')->weeklyOn(2, '04:00')->timezone(SiteTimezone::current());
Schedule::command('gadya-cms:prune-analytics')->weeklyOn(1, '03:00')->timezone(SiteTimezone::current());
Schedule::command('gadya-cms:prune-trash')->daily();
Schedule::command('gadya-cms:prune-activity')->weekly();
```
