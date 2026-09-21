# Upgrading

## From the Gadya Media portal

`gadya-cms:install` publishes `.github/workflows/gadya-update.yml`. **Sites → Update** in the portal runs it on the site's repository, where it:

1. updates the Gadya packages;
2. runs `migrate`, `filament:assets` and the site's own test suite;
3. commits the result to the default branch when it is a patch release and the tests passed, or opens a pull request when it is anything larger or the tests failed.

Nothing is changed on the server: the next deploy takes the new version from git, as it always does. Run it by hand from the repository's Actions tab if you'd rather, and tick *Open a pull request even for a patch release* to look at every change first.

A site that has no such workflow (installed before this version) gets it by running `php artisan gadya-cms:install` once, which leaves everything else alone.

## With an AI agent

The package ships a `gadya-cms-upgrade` Boost skill. On any site, ask your agent:

> Upgrade gadya/cms to the latest version and turn on everything, using the gadya-cms-upgrade skill.

On a site installed before the skill existed, the agent does not have it yet. Ask instead:

> Run `composer update gadya/cms -W` and `php artisan boost:update --discover`, then follow the gadya-cms-upgrade skill.

The skill:

1. Audits the site with `gadya-cms:audit`.
2. Updates the package, reads the changelog and the notes below, and refreshes the skills.
3. Works down the audit until nothing is left to do: copying config keys with the site's own values, running migrations, switching features on, scheduling jobs, and adding the Blade directives.
4. Runs the tests and commits on a branch without pushing.
5. Lists what only a person can do: deploy, server cron, DNS, Search Console, API keys, and menu links.

It never touches content in the database.

### Doing it by hand

```bash
composer update gadya/cms -W      # or composer require gadya/cms:^0.N -W for a new minor
php artisan migrate
php artisan filament:assets
php artisan boost:update --discover
php artisan gadya-cms:audit       # then fix each "!" it lists
```

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
