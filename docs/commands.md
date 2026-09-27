# Commands

| Command | Purpose |
| --- | --- |
| `gadya-cms:audit` | What this application has not yet taken up from the installed version: config keys, migrations, features, schedule, templates, assets, skills (`--json`). Fails while anything is left to do. |
| `gadya-cms:install` | Publish config, migrate, seed, index photos, publish assets, create the first administrator. Safe to repeat. |
| `gadya-cms:editor` | Create a user who can sign in (`--name`, `--email`, `--password`, `--role=admin`) |
| `gadya-cms:password` | Set a new password for someone who signs in (`--email`, `--password`, `--generate`) |
| `gadya-cms:doctor` | Report the image driver, WebP support, HEIC support and optimiser binaries |
| `gadya-cms:make:page-template` | Scaffold a Blade template wired to the live editor (`--layout=`, `--force`) |
| `gadya-cms:export` | The whole site as a zip or JSON (`--path=`, `--with-media`, `--array` for the old PHP array) |
| `gadya-cms:import` | Bring an export into this install (`--replace`) |
| `gadya-cms:media-variants` | Generate responsive WebP variants for older and legacy photos (`--force`) |
| `gadya-cms:import-legacy-media` | Index images already shipped with the site |
| `gadya-cms:import-legacy-content` | Import from a pre-Filament `site_contents` table |
| `gadya-cms:publish-due` | Publish the draft if a publish was scheduled for now or earlier |
| `gadya-cms:check-links` | Find links on the site that lead nowhere (`--external`) |
| `gadya-cms:search-console` | Fetch queries and landing pages from Google (`--days=28`) |
| `gadya-cms:pagespeed` | Run Lighthouse through PageSpeed Insights (`--url=`, `--limit=5`, `--strategy=`) |
| `gadya-cms:drift-digest` | Email what has quietly gone out of date (`--show` to print it instead) |
| `gadya-cms:favicon` | Draw the browser-tab icon from the site's logo (`--forget` to redraw, `--write` to save it into `public/` for a web server that answers `/favicon.ico` from disk) |
| `gadya-cms:backup-drill` | Open the newest backup and check its database dump could be restored |
| `gadya-cms:takeout` | Pack the whole site into one zip the client owns (`--path=`) |
| `gadya-cms:fix` | Describe photos and write missing search snippets into the draft, and print what is left for a developer (`--photos`, `--pages`, `--limit=25`) |
| `gadya-cms:agent-ready` | Score readiness for search engines and AI assistants (`--live`) |
| `gadya-cms:analytics-digest` | Email the summary to the people who signed up for it (`--days=7`) |
| `gadya-cms:prune-analytics` | Delete page views and events past the retention window (`--days=`) |
| `gadya-cms:prune-trash` | Empty the trash of pages and articles nobody restored (`--days=`) |
| `gadya-cms:prune-activity` | Delete old activity entries and long-fixed broken links (`--days=`) |
| `gadya-cms:push-submissions` | Send the Gadya portal any enquiries from the last week it has not had (`--limit=100`). Scheduled every five minutes by the package itself on a paired site. |

# Scheduling

In `routes/console.php`. Nothing below is required, but a scheduled publish never goes out without the first line:

```php
Schedule::command('gadya-cms:publish-due')->everyFiveMinutes();
Schedule::command('gadya-cms:search-console')->dailyAt('05:00');
Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00');
Schedule::command('gadya-cms:check-links')->weeklyOn(2, '03:00');
Schedule::command('gadya-cms:pagespeed')->weeklyOn(2, '04:00');
Schedule::command('gadya-cms:drift-digest')->twiceMonthly(1, 15, '08:00');
Schedule::command('gadya-cms:backup-drill')->monthlyOn(3, '05:00');
Schedule::command('gadya-cms:prune-analytics')->weeklyOn(1, '03:00');
Schedule::command('gadya-cms:prune-trash')->daily();
Schedule::command('gadya-cms:prune-activity')->weekly();
```

# Configuration

```bash
php artisan vendor:publish --tag=gadya-cms-config
```

Every option in `config/gadya-cms.php` is documented in the file. The published file is merged over the package's defaults all the way down, so it only needs the keys the site sets: a key a release adds, at any depth, takes its default until you copy it. Two kinds of value are the site's whole answer and never mixed with the defaults: lists (`locales.enabled => ['es']` is Spanish alone, not English and Spanish), and the maps a site fills in entry by entry - `editable_fields`, `globals`, `navigation.menus`, `pages.types`, `pages.content_fields`, `users.roles`, `forms.forms`, `fonts.display`, `fonts.sans`, `menus.dietary` and `seo.content_signals`.

# Deploying

On every deploy:

```bash
php artisan migrate --force
php artisan filament:assets
php artisan optimize:clear
npm run build
```

After a deploy that brings a new release, `php artisan gadya:upgrade` does the first three and anything else the release needs on the server (see [Upgrading](upgrading.md)); the Gadya portal runs it for you in a rollout.

And once: a queue worker (photos, articles, emails), `MAIL_*` for invitations, enquiries, replies and the digest, the scheduler running, and Reverb with `REVERB_*` (and websockets allowed through the CDN) for the live panel.
