# Upgrading

## From the Gadya Media portal

`gadya-cms:install` publishes `.github/workflows/gadya-update.yml`. The portal runs it on the site's repository - a site at a time from its Update button, or many in a **rollout**, canaries first and a few at a time - where it:

1. updates the Gadya packages;
2. runs `php artisan gadya:upgrade --phase=code`, the release's changes to the repository (below);
3. runs the site's own test suite;
4. commits the result, and pushes it to the branch it ran on when the tests passed and the new version is within the run's `merge` policy - `patch` (the same minor, the default), `minor` (the same major; on 0.x any 0.x) or `never` - or opens a pull request for a person otherwise.

After the deploy, the portal sends the site `upgrade.finish`, which runs `php artisan gadya:upgrade` on the live server, and then watches the site's check-ins, health and errors for ten minutes before it calls the site done.

The first line of the workflow, `# gadya-update-template: 2`, says which template the repository has. GitHub does not let a workflow change workflows, so the portal refreshes the file itself when gadya/cms ships a newer one (it needs a token that may write workflows). A site with no such workflow (installed before it existed) gets it by running `php artisan gadya-cms:install` once, which leaves everything else alone. The template still works on a site whose gadya/connect has no `gadya:upgrade` yet: there it runs `migrate` and `filament:assets` as the first template did.

Each run ends with a line the portal reads, in the commit message, the pull request and the run's summary:

```
Gadya-Update: before=0.14.8 after=0.15.0 tests=success merged=true rollout=12
```

## `gadya:upgrade`

gadya/connect 0.6 runs the mechanical part of an upgrade as steps that are safe to run again - each checks whether it still has anything to do - so the same command is right after every release:

```bash
php artisan gadya:upgrade --phase=code   # in the repository, before the tests; commit what it changes
php artisan gadya:upgrade                # on the server after the deploy (--phase=server)
```

`--dry-run` lists what would run; `--json` prints what ran, what had nothing to do and what failed. The steps:

| Phase | Step | Does |
| --- | --- | --- |
| code | `cms.0.15.0.notifications-table` | Adds Laravel's notifications table migration (`make:notifications-table`) for the panel's bell, unless a migration or schema dump already creates it |
| code | `cms.form-sections` | Adds `@cmsSection($section, $index)` as the first line of every ordinary loop over a page's sections, so a client's Form section is drawn; `gadya-cms:audit` names any loop it could not change |
| code | `cms.workflow-template` | Says when `.github/workflows/gadya-update.yml` is older than the template this release ships. It never writes it: the portal refreshes it, or copy `vendor/gadya/cms/resources/github/gadya-update.yml` over it |
| code | `connect.boost-update` | `boost:update --discover`, where Boost is set up |
| server | `connect.migrate` | `migrate --force`, first; if it fails nothing else runs |
| server | `cms.storage-link` | `storage:link` when the photo library's public link is missing |
| server | `cms.caches` | Forgets the CMS's cached site (every language), redirects, reviews and mail status |
| server | `connect.optimize-clear` | `optimize:clear` |
| server | `connect.filament-assets` | `filament:assets` |

What is left for a person - business details in config, switching features on, templates - is still `gadya-cms:audit`'s list; the notes below say what each release asks.

## With an AI agent

The package ships a `gadya-cms-upgrade` Boost skill. On any site, ask your agent:

> Upgrade gadya/cms to the latest version and turn on everything, using the gadya-cms-upgrade skill.

On a site installed before the skill existed, the agent does not have it yet. Ask instead:

> Run `composer update gadya/cms -W` and `php artisan boost:update --discover`, then follow the gadya-cms-upgrade skill.

The skill:

1. Audits the site with `gadya-cms:audit`.
2. Updates the package, reads the changelog and the notes below, and refreshes the skills.
3. Runs `gadya:upgrade` for the mechanical part, then works down the audit until nothing is left to do: switching features on, scheduling jobs, adding the Blade directives, and filling in this site's details.
4. Runs the tests and commits on a branch without pushing.
5. Lists what only a person can do: deploy, server cron, DNS, Search Console, API keys, and menu links.

It never touches content in the database.

### Doing it by hand

```bash
composer update gadya/cms -W      # or composer require gadya/cms:^0.N -W for a new minor
php artisan gadya:upgrade --phase=code
php artisan gadya:upgrade         # migrate, the release's server steps, optimize:clear, filament:assets
php artisan gadya-cms:audit       # then fix each "!" it lists
```

On a site whose gadya/connect is older than 0.6 (no `gadya:upgrade`), run `migrate`, `filament:assets`, `optimize:clear` and `boost:update --discover` instead.

## 0.14.8 → 0.15.0

The published `config/gadya-cms.php` is now merged over the package's defaults at every depth, so a key a release adds - even inside an array the site has published - takes its default, and copying it is only needed to change it. `gadya-cms:audit` lists keys the file lacks as optional. Lists and the site-owned maps are still taken whole from the site; see [Configuration](commands.md#configuration). Refresh the update workflow to template 2 from the portal (or copy `vendor/gadya/cms/resources/github/gadya-update.yml` over `.github/workflows/gadya-update.yml`), and update gadya/connect to 0.6 for `gadya:upgrade`.

`php artisan migrate` adds `pushed_at` and `consent` to `gadyacms_form_submissions`, `decorative` to `gadyacms_media`, and creates `gadyacms_change_requests`. The new `portal` key takes its defaults until you copy it. For the notification bell that announces requested changes, the application needs Laravel's `notifications` table: `gadya:upgrade --phase=code` adds the migration (or `php artisan make:notifications-table`). Tell the client that a photo now needs a description, or to be marked as decoration, before it can be saved. See [The Gadya portal](portal.md).

`php artisan migrate` adds the `gadyacms_menus`, `gadyacms_menu_sections` and `gadyacms_menu_items` tables. The new `menus`, `hours` and `privacy` keys take their defaults until you copy them into `config/gadya-cms.php`. Nothing on the public site changes until someone uses them:

- **Opening hours** appears under Appearance for every site. Once they are filled in and published, add `<x-gadya-cms::opening-hours />`, `<x-gadya-cms::open-status />` or `<x-gadya-cms::todays-hours />` where the site shows its hours, and replace hand-written hours in templates and JSON-LD. `@cmsSeo`'s business node picks them up by itself. Check `hours.timezone`.
- **Food menus** is off: add `->foodMenus()` to the plugin on a restaurant, café or bakery, then `<x-gadya-cms::menu menu="..." />` in the menu page's template.
- **Privacy choices** is off: on a site that loads Google Analytics, Ads or any pixel (`gadya-cms:audit` lists them), wrap each tag in `<x-gadya-cms::consented-script>`, add `<x-gadya-cms::consent-banner />` before `</body>` and `<x-gadya-cms::privacy-choices-link />` to the footer, then switch it on under Settings → Privacy choices and publish. See [Privacy choices](privacy.md).
- **Forms** can be built by the client under Content → Forms. `php artisan migrate` creates `gadyacms_forms`, `gadyacms_form_versions`, `gadyacms_form_events`, `gadyacms_form_drafts` and `gadyacms_form_webhook_deliveries`, and adds `form_id`, `form_version`, `files` and `meta` to `gadyacms_form_submissions`. `gadya:upgrade --phase=code` adds `@cmsSection` to the section loop so a Form section is drawn (check `gadya-cms:audit`). Configured forms are unchanged. Roles with `content` can now build forms; list `forms` for a role that should build forms without editing pages. Offer the client the new **Settings → Text messages** (her own Twilio account) and **Settings → Spam protection**. To hand the site's own forms to the client, use the `gadya-cms-forms` skill. See [Forms](forms.md).
- **Languages** are off: the migration creates `gadyacms_translations`; add `locales.enabled => ['en', 'es']` to `config/gadya-cms.php` to serve Spanish at `/es/...` (a list is the site's whole answer, so keep `en` in it). See [Multilingual](multilingual.md).

## 0.12.0 → 0.13.0

`php artisan migrate` adds `notes`, `follow_up_at` and `answered_at` to `gadyacms_form_submissions`. Nothing else is required. Tell the client that *Automatic replies* is now **Enquiry emails**, and that she can add who is told about new enquiries there.

## 0.11.4 → 0.12.0

Nothing is required. A site moving a hand-written `<head>` onto `@cmsSeo` may want the three new `seo` keys - `suffix_written_titles`, `organization_schema` and `organization.anchor` - described in the changelog; copy them into `config/gadya-cms.php` if so.

## 0.10.4 → 0.11.0

Copy the new `brand.favicon` and `brand.favicon_source` keys into `config/gadya-cms.php`. Nothing else is needed: a site with its own `public/favicon.ico` is untouched, and one without now serves an icon drawn from its logo. Run `php artisan gadya-cms:favicon` to see which it did, and whether it found a logo to draw from.

## 0.9.1 → 0.10.0

Copy the new `seo.discovery` and `seo.mcp` keys into `config/gadya-cms.php` (the published file overrides the package's `seo` array wholesale, so a key you do not copy is simply absent). A site with its own catch-all page route needs nothing: the discovery routes are all under `.well-known`.

A site that runs its own MCP server should set `seo.mcp.endpoint` so the server card and the catalogues name it.

**If a readiness checker says Content Signals or Link headers are missing**, check that nothing is shadowing the package's routes: a static `public/robots.txt` and an application's own `/robots.txt` route both win over ours, and the Link headers come from the home page's response, so a page served by a cache in front of Laravel may lose them.

## 0.8.1 → 0.9.0

`php artisan migrate` has nothing new to do. Copy the new `accessibility` block into `config/gadya-cms.php`, and add the digest to `routes/console.php`:

```php
Schedule::command('gadya-cms:drift-digest')->twiceMonthly(1, 15, '08:00');
```

The statement is served at `/accessibility-statement` unless `accessibility.statement` is false. A site with its own catch-all page route must add `accessibility-statement` to `pages.route_excluded_slugs`.

## 0.8.0 → 0.8.1

`php artisan migrate` adds the `failures` column to `gadyacms_page_scores` and the `gadyacms_fixes` table. Nothing else is needed: **Settings → Speed & accessibility** appears wherever `search()` is on, and uses Gadya Media's AI key over the portal link on a paired site with no key of its own.

## 0.4 → 0.5

Gadya CMS now requires gadya/connect.

1. Update and migrate. The migration adds one table, `gadya_connect_connections`:

   ```bash
   composer require gadya/cms:^0.5 -W
   php artisan migrate
   php artisan filament:assets
   ```

2. Pair the site. In the Gadya portal choose **Sites → Connect a site**, then on the live server run `php artisan gadya:connect <code>`, or paste the code under **Settings → Gadya Support**.
3. The scheduler must be running (`php artisan schedule:run` every minute). The check-in rides on it.
4. On Laravel Forge, keep `/gadya-connect` reachable. Nothing to do unless a custom nginx rule blocks it.

A site that stays unpaired behaves as before, apart from the Get help page and its top-bar button.

## 0.4.6 → 0.4.7

Replace any hand-pasted `<gadya-built-by>` script and tag in the footer with `@gadyaBuiltBy`. If you publish the config and want to change the badge, add the `built_by` array.

## 0.4.4 → 0.4.5

Nothing to change. `gadya-cms:audit` and the `gadya-cms-upgrade` skill are new; run `php artisan boost:update --discover` to install the skill.

## 0.4.3 → 0.4.4

Run `filament:assets`. If you publish `config/gadya-cms.php`, add to its `seo` array: `content_signals`, `link_headers` and `domains` (list every domain the business owns, the site's first). Without them robots.txt carries no Content-Signal lines and **Get found** checks only APP_URL's host.

## 0.2 → 0.3

Run the migrations (photo variants, search snapshots, page scores) and `filament:assets`.

**Roles.** `users.roles` may now carry abilities. A role left as a plain label keeps working (everything but settings and the team). To use the new *contributor* role, add it to your role enum and to `canManageContent()`, and list it in `users.roles`. Redirects, AI and Search & speed now need the `settings` ability - an editor no longer sees them unless you grant it.

**Site details.** The announcement, phone and address moved from *Site details* to **Appearance → Everywhere**, driven by the new `globals` config; *Site details* is now *Locations*. Tests that filled those fields on `SiteDetails` should target `Gadya\Cms\Filament\Pages\Globals`.

**Published config.** Add the nested keys 0.3 reads to any array you override: `globals`, `media.variants`, `seo.llms`, `seo.markdown`, `seo.ai_crawlers`, `seo.organization`, `users.roles` (new shape).

**Export.** `gadya-cms:export` now writes a zip/JSON of the whole site; the old PHP-array format is `--array`.

**Doctor mocks** are unchanged from 0.2.

## 0.1 → 0.2

Run the migrations; they add the options, articles, redirects, form submissions and photo folder tables and columns.

```bash
php artisan migrate --force
php artisan filament:assets
```

**Published config.** If you published `config/gadya-cms.php` under 0.1, add the nested keys 0.2 reads, or the array they belong to loses them:

- `pages.home_slug`, `pages.paths`, `pages.content_fields`
- `navigation.locations_type`
- `document`, `preview`, `blog`, `ai`, `forms`, `seo` (top-level; the defaults apply if absent)
- add `'blog'` to `pages.reserved_slugs` and `pages.route_excluded_slugs` if the public blog routes are on

**Routes.** The package no longer calls `route('home')`, `route('pages.show')` or `route('locations.show')`. Where a page lives is answered by `Gadya\Cms\Contracts\ResolvesPagePaths`; the default is slug-based. A site with nested addresses binds its own through `pages.paths` (see [site-document.md](site-document.md)).

**Hidden pages.** `PageRegistry::isArchived()` still exists; use `isHidden()` in your controllers so scheduled pages 404 before their time, and `EditContext::showsDraft()` rather than `isEnabled()` so preview links work.

**Head tags.** Replace a hard-coded `<title>` and description with `@cmsSeo($page)`.

**robots.txt.** Delete `public/robots.txt` to let the package serve one that points at the sitemap, or keep yours.

**Doctor command.** Anything mocking `ImageCapabilities` needs `driverName()`, `hasImagick()` and `supportsWebp()`.
