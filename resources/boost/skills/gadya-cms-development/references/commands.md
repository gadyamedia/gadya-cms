# Commands

| Command | Purpose |
| --- | --- |
| `gadya-cms:install` | Publish config, migrate, seed, index photos, publish assets, create the first administrator. Safe to repeat. |
| `gadya-cms:editor` | Create a user who can sign in (`--name`, `--email`, `--password`, `--role=admin`) |
| `gadya-cms:doctor` | Report the image driver, WebP support, HEIC support and optimiser binaries |
| `gadya-cms:export` | The whole site as a zip or JSON (`--path=`, `--with-media`, `--array` for the old PHP array) |
| `gadya-cms:import` | Bring an export into this install (`--replace`) |
| `gadya-cms:make:page-template` | Scaffold a Blade template wired to the live editor |
| `gadya-cms:media-variants` | Generate responsive variants for older photos (`--force`) |
| `gadya-cms:search-console` | Fetch queries and landing pages from Google (`--days=28`) |
| `gadya-cms:pagespeed` | Run Lighthouse through PageSpeed Insights (`--url=`, `--limit=5`, `--strategy=`) |
| `gadya-cms:agent-ready` | Score readiness for search engines and AI assistants (`--live`) |
| `gadya-cms:import-legacy-media` | Index images already shipped with the site |
| `gadya-cms:import-legacy-content` | Import from a pre-Filament `site_contents` table |
| `gadya-cms:prune-analytics` | Delete page views and events past the retention window (`--days=`) |
| `gadya-cms:analytics-digest` | Email the summary to the people who signed up for it (`--days=7`) |

# Configuration

```bash
php artisan vendor:publish --tag=gadya-cms-config
```

Every option in `config/gadya-cms.php` is documented in the file. Note that the package merges only top-level keys, so a published file must carry every nested key of any array it overrides (`pages`, `navigation`, `analytics`, ...). When a release adds a nested key, [upgrading.md](upgrading.md) says so.

# Deploying

On every deploy:

```bash
php artisan migrate --force
php artisan filament:assets
php artisan optimize:clear
npm run build
```

And once: a queue worker, `MAIL_*` for invitations, enquiries and the digest, and Reverb with `REVERB_*` (and websockets allowed through the CDN) for the live panel.
