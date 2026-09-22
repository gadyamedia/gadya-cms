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

**Publish changes** asks *when*. Blank means now; a time in the future holds the whole draft until then, and the dashboard says so until it goes out. Publishing again changes or cancels it.

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

## Everything to schedule

```php
Schedule::command('gadya-cms:publish-due')->everyFiveMinutes();
Schedule::command('gadya-cms:search-console')->dailyAt('05:00');
Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00');
Schedule::command('gadya-cms:check-links')->weeklyOn(2, '03:00');
Schedule::command('gadya-cms:pagespeed')->weeklyOn(2, '04:00');
Schedule::command('gadya-cms:prune-analytics')->weeklyOn(1, '03:00');
Schedule::command('gadya-cms:prune-trash')->daily();
Schedule::command('gadya-cms:prune-activity')->weekly();
```
