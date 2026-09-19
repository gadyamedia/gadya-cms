---
name: gadya-cms-upgrade
description: Audit a site's gadya/cms install, upgrade it to the latest release, and switch on and wire up every feature it has not taken up yet - config, migrations, plugin switches, schedule, templates - then hand over the steps only a person can do.
---

# Gadya CMS Upgrade

## When to use this skill

Use it when asked to update or upgrade gadya/cms, to audit a site's install, to "turn on everything", or to bring a client site up to the latest version. It works the same on every site: `gadya-cms:audit` says what is missing, and you work down its list until nothing is left to do.

Documentation for the installed version is in `vendor/gadya/cms/docs/`. Read the file named in each step before you change anything it covers. After the update, `vendor/gadya/cms/docs/` is the new version's documentation.

## Rules

- **Code and config only, never content.** Pages, menus, photos and articles live in the database and belong to the client. Never run `migrate:fresh`, `db:seed`, `gadya-cms:import*` or `gadya-cms:install --force`, and never write to `gadyacms_*` tables. When a feature needs content (a menu link to `/events`, a first event), list it for the person to do in the panel.
- **Branch first, never push.** Start from a clean tree (`git status --short` is empty). Pushing often deploys; leave that to the person.
- **Never move or delete a git tag**, in the app or the package.
- **Business facts come from the site, not the package.** The package config's examples (Springfield, 555 numbers, example.com) are placeholders. Take names, phones, domains and addresses from `config/site.php`, the existing layout, `.env` and the published document. Ask only when none of those has the answer.
- **Some things stay off unless the person asks:**
  - comments (`blog.comments.enabled`), because someone must moderate them
  - coming-soon mode
  - `->brand(false)` or `->analytics(false)` on a site that chose them deliberately. The audit marks them; ask.
- **API keys are the client's.** AI and Search Console keys are entered in the panel and stored encrypted, so never put them in config or `.env`.
- Follow the application's own rules in `.ai/rules` and its CLAUDE.md or AGENTS.md. Run Pint on PHP you touch.

## 1. Audit where the site is

```bash
git status --short                       # must be empty
git switch -c chore/gadya-cms-upgrade
composer show gadya/cms | grep versions  # installed
composer show gadya/cms --latest --format=json | php -r 'echo json_decode(stream_get_contents(STDIN))->latest, PHP_EOL;'
grep '"gadya/cms"' composer.json         # the constraint
php artisan gadya-cms:audit              # from 0.4.5; skip on older installs
php artisan test --compact               # the baseline: note anything already failing
```

Write down the installed version, the latest version, the constraint and every failing test before you start.

## 2. Update the package

On 0.x a minor release may break things, so `^0.4` never reaches 0.5:

- **Same minor (0.4.3 → 0.4.9):** `composer update gadya/cms -W`
- **New minor (0.4 → 0.5):** `composer require gadya/cms:^0.5 -W`

Then:

```bash
php artisan migrate
php artisan filament:assets
php artisan optimize:clear
```

## 3. Read what changed

Read `vendor/gadya/cms/CHANGELOG.md` from the old version up to the new one. Also read every section of `vendor/gadya/cms/docs/upgrading.md` between them. These are the breaking changes and the "add these keys" notes; apply each one. The published `config/gadya-cms.php` overrides the package's arrays wholesale (top-level merge only), so any key a release adds must be copied into it.

## 4. Refresh the skills

```bash
php artisan boost:update --discover
```

`--discover` picks up skills a release added, including this one on a site that never had it. Check that `boost.json` then lists `gadya-cms-content`, `gadya-cms-development` and `gadya-cms-upgrade` under `skills`.

## 5. Work down the audit

```bash
php artisan gadya-cms:audit --json
```

Each check has a `group`, `label`, `status` (`ok`, `todo`, `optional`) and a `fix`. Clear every `todo` and decide every `optional`, group by group.

### Config

- Copy every key in the "Every key the package reads" finding from `vendor/gadya/cms/config/gadya-cms.php` into `config/gadya-cms.php`, in the same place, with this site's values and the package's comment above it.
- Fill `seo.site_name`, `seo.organization` (telephone, email, address, area, `same_as` social links) and `seo.domains` (every domain the business owns, the live one first; `dig NS domain` tells you if one is parked elsewhere). Also fill `seo.content_signals`.
- Add every new editable path to `editable_fields`. The live editor refuses anything not listed.

### Database

- Run `php artisan migrate`.

### Features

Each feature switch, and what else turning it on needs:

| Switch | Turn on | Also build | Read |
| --- | --- | --- | --- |
| `->blog()` / `blog.routes` | remove `->blog(false)`; `blog.routes => true` | `blog.layout` is the site's public layout; `blog.prefix`; add `blog` to `pages.reserved_slugs` and `pages.route_excluded_slugs` | `articles-and-ai.md` |
| `->events()` / `events.routes` | `events.routes => true` | `events.heading` and `events.description` in the site's voice; `events` in both slug lists | `events-and-search.md` |
| `->search()` / `site_search.routes` | `site_search.routes => true` | `@cmsSearchForm` in the header or footer, styled like the site; `search` in both slug lists; `site_search` in `analytics.events` | `events-and-search.md` |
| `->newsletter()` / `newsletter.enabled` | `newsletter.enabled => true` | `@cmsNewsletterForm` in the footer, wording in `newsletter.*` | `events-and-search.md` |
| `->forms()` | on by default | every enquiry form posts through `@cmsForm('key')` plus `@cmsFormStatus('key')`, with the form defined under `forms.forms` | `forms.md` |
| `->analytics()` / `analytics.enabled` | on | `data-analytics="…"` on the site's key buttons (booking, directions, CTA), with each name listed in `analytics.events` | `analytics.md` |
| `->ai()` | on | nothing in code: the client enters the key under Settings → AI | `articles-and-ai.md` |
| `->redirects()`, `->team()`, `->profile()`, `->unsavedChangesAlerts()`, `->brand()` | on | nothing | `roles-and-globals.md`, `media-and-team.md` |
| `seo.sitemap`, `seo.robots`, `seo.llms`, `seo.markdown`, `seo.link_headers` | `true` | delete any static `public/robots.txt` or `public/sitemap.xml` | `seo.md`, `search-and-readiness.md` |
| `blog.comments.enabled` | only if asked | `blog.comments.notify` addresses | `articles-and-ai.md` |

A site with its own catch-all page route (`/{slug}`) must list every new top-level prefix in `pages.route_excluded_slugs`, or the page route swallows it. Check with `php artisan route:list`.

### Schedule

Add each missing line from the audit to `routes/console.php`. They are idempotent, so schedule all of them even before Search Console is connected. The server needs its cron running `php artisan schedule:run` every minute; put that in the hand-over.

### Templates

- The public layout has `@cmsSeo($page)` in `<head>`, replacing any hand-written title, description, canonical or Open Graph tags. It also has `@cmsToolbar` just before `</body>`.
- Search and newsletter forms go where a visitor looks for them. Match the site's own markup and CSS rather than leaving them unstyled.
- Hard-coded text in templates that the client should be able to change becomes `@editable(...)` against the document, with the path in `editable_fields`. Do this only where the template already reads the document for that element; do not invent new content.

### Install

- Run `php artisan filament:assets` whenever the stylesheet check fails.
- Missing gates: define `manage-content` and `manage-users` as `docs/installation.md` shows.

## 6. Verify

```bash
php artisan gadya-cms:audit                     # no "!" left; every "○" decided
vendor/bin/pint --dirty --format agent
php artisan test --compact                      # nothing that passed before may fail now
php artisan gadya-cms:agent-ready               # note the score for the hand-over
php artisan route:list --path=events            # and blog, search, sitemap.xml, robots.txt, llms.txt
```

Request each public address the upgrade touched through the app (`/`, `/sitemap.xml`, `/robots.txt`, `/llms.txt`, `/blog`, `/events`, `/search?q=a`, `/events.ics`) with `get-absolute-url` and `curl -s -o /dev/null -w '%{http_code}'`. Each must answer 200. If `/robots.txt` answers 404 locally with the right text, the web server swallowed it (see *Get found* in `search-and-readiness.md`); that is a server fix for the hand-over, not a code change.

When a test fails because of a change the release intended (a renamed heading, a new default), update the test to match. Never delete a test to make the suite pass. Anything else that fails is a regression: fix it or report it.

## 7. Commit and hand over

Commit on the branch: `chore: gadya/cms <old> → <new> - <what was switched on>`. Do not push.

Then tell the person, in this order:

1. **Versions:** the old and new versions, and the changelog highlights that matter to this client.
2. **Done:** what you switched on and wired up, and the audit before and after (to-do counts).
3. **Their decisions:** any optional item you left off, and why.
4. **After deploying,** things only a person can do:
   - Make sure the deploy script runs `php artisan migrate --force` and `php artisan filament:assets`.
   - Make sure the cron runs `schedule:run` every minute.
   - Laravel Forge: delete the `location = /robots.txt` line in the site's nginx config if Settings → Get found says robots.txt answers 404.
   - DNS and Search Console: follow **Settings → Get found** for each domain. It lists the exact records, and the sitemap address to submit.
   - Keys: AI under **Settings → AI**; Google under **Settings → Search & speed**.
   - Content: add new sections to the menu (**Appearance → Menu**) and publish, e.g. What's on or the blog. Write missing page descriptions (the readiness score lists them).
