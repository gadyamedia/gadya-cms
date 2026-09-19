# Changelog

All notable changes to `gadya/cms` are documented here.

## 0.4.5

### Added

- **`gadya-cms:audit`** lists what an application has not yet taken up from the installed version, each with its fix:
  - config keys a release added
  - migrations not run
  - plugin switches and feature flags that are off
  - scheduled jobs missing from `routes/console.php`
  - `@cmsSeo`, `@cmsToolbar`, search and newsletter forms missing from the templates
  - a stale panel stylesheet, missing gates, static `robots.txt` or `sitemap.xml` files, and out-of-date Boost skills

  `--json` is for agents. The command fails while anything is left to do.
- **The `gadya-cms-upgrade` Boost skill.** Ask an agent to *"upgrade gadya/cms to the latest version and turn on everything"*. It audits the site, updates the package, applies the upgrade notes, switches on and wires up every feature, runs the tests, commits on a branch, and lists what only a person can do. It never touches content.

## 0.4.4

### Added

- **Settings → Get found**: live checks that `robots.txt`, `sitemap.xml` and `llms.txt` actually answer (and the Forge nginx fix when `robots.txt` comes back 404), the sitemap address with step-by-step Search Console and Bing instructions, and the DNS records every domain in `seo.domains` needs, each looked up live and marked in place, missing or pointing elsewhere.
- `Content-Signal` lines in `robots.txt` (`seo.content_signals`, default `search=yes, ai-input=yes, ai-train=no`) and a matching readiness check.
- `Link` headers on the home page pointing agents at `llms.txt` and the sitemap (`seo.link_headers`).

### Changed

- **The dashboard, redesigned.** Rows no longer touch the card edges; headline figures sit in one strip under a live-visitor pill and a segmented date range; the chart has gridlines, a "busiest day" figure and an empty state; pages, sources and countries show proportional share bars; countries get full names; devices a split bar; readiness checks stack instead of cramming into two columns; notices merge into one; "Someone" is now "A team member".

## 0.4.3

### Added

- **Add with a password** on the Team screen: create someone without sending an email, with a password made up for you (or your own), then copy their sign-in details - address, email, password and how to change it - to pass on however you like.
- **Set a new password** on each person's row, handing over the new details the same way.

## 0.4.2

### Fixed

- **Coming-soon mode locked the client out of her own panel.** The check ran before the session started, so nobody ever looked signed in, and Livewire 4's hashed update path (`/livewire-xxxx/update`) was not recognised as the panel's - every click, including Publish, got the notice. It now runs in the web group and matches Livewire by its prefix.
- The coming-soon notice wears the site's brand: its colours, display font and logo.

## 0.4.1

Identical to 0.4.0 in every line of code; it adds the two screenshots the
README links to, and exists because 0.4.0's tag was moved after it had
been published. A published version's contents never change, so the old
tag stays where it was and this is the one to use.

## 0.4.0

The release that answers "but WordPress does…".

### Added

- **Categories and tags** for articles, with archive pages of their own, and **related articles** at the foot of each one.
- **Comments**, off unless a site wants them, moderated unless it says otherwise, emailed in full.
- **Duplicate** for pages and articles.
- **A trash**: a deleted page is off the site at once and back in one click for thirty days, then emptied by `gadya-cms:prune-trash`.
- **Fields per page type**, so a location is not edited through a form built for a legal page.
- **Saved blocks**: keep a section and drop a copy of it into another page.
- **The site's own search box**, with what people searched for - and did not find - counted on the dashboard.
- **Events**: things that happen on a date, sorted for you, with an `.ics` feed and `Event` structured data.
- **A mailing list** with an unsubscribe link that needs no account and an export in the columns mailing services read.
- **A focal point** per photo, rendered by `@siteFocus`, and **alt text written by looking at the photograph**.
- **A profile page**, **unsaved-changes warnings**, and **more than one menu**.
- **An activity log**: who changed what, when.
- **Publishing at a time**: hold the whole draft until Friday at nine.
- **Coming-soon mode** that closes the site without closing the panel, with a password link to share.
- **Broken links** from both ends - what visitors asked for and what the site links to - fixed with one click.
- **Automatic replies**: the thank-you email each form sends, written in the panel.
- `gadya-cms:prune-trash`, `gadya-cms:prune-activity`, `gadya-cms:publish-due`, `gadya-cms:check-links`.

### Changed

- Pages are soft-deleted; `Page::query()` no longer sees deleted rows. See [upgrading](docs/upgrading.md).
- **Publish changes** asks when, so it can be scheduled; passing no time still publishes now.

## 0.3.0

### Added

- **Roles with abilities** (`content`, `articles`, `photos`, `enquiries`, `publish`, `settings`); a contributor role that writes articles but cannot publish a page. Plain-label roles keep working.
- **Everywhere**: the announcement, phone, footer text and any other global, from a configured list, on one screen and on the page.
- **Publish changes** on every screen that saves a draft.
- **`gadya-cms:make:page-template`**: a Blade template with every configured field already editable.
- **A demo site** (`composer demo`) that uses every feature, for trying, screenshots and development.
- **Moving a site**: `gadya-cms:export` (zip or JSON, photos included) and `gadya-cms:import`. Secrets never travel.
- **Responsive photos**: variants at configured widths, `@siteSrcset`, `@siteImage($ref, $width)`, and a backfill command.
- **Search Console** on the dashboard through a service account; **PageSpeed Insights** scores for the top pages; a **readiness score for AI assistants**.
- **For AI assistants**: `/llms.txt`, named AI crawlers in `robots.txt`, Organisation/WebSite/Article JSON-LD, and every page and article as `text/markdown` on request.
- A second Boost skill, `gadya-cms-content`, for people who write and publish rather than build.

### Changed

- *Site details* is now *Locations*; the announcement, phone and address moved to *Everywhere*.
- Redirects, AI settings and Search & speed need the `settings` ability.
- `gadya-cms:export` writes the new format; `--array` gives the old one.

## 0.2.2

- The Boost skill carries the whole `docs/` folder as references, kept identical by a test and `composer sync-docs`.

## 0.2.1

- Laravel Boost guideline and `gadya-cms-development` skill, installed by `boost:update --discover`.

## 0.2.0

The package now stands on its own: it is tested against a minimal host
application, on PHP 8.3 and 8.4, and no longer assumes any route the host
defines. See [docs/upgrading.md](docs/upgrading.md) for the handful of
changes an application upgrading from 0.1 makes.

### Added

- **Articles**, with a writing screen, a findability score on every save,
  scheduled publishing, and optional public routes in the host's layout.
- **Writing with AI**: provider, model and key chosen in the panel and
  stored encrypted; a voice for the business; whole drafts written in the
  background, rewrites of existing articles, and search snippets from a
  page's own words. Any provider Laravel's AI SDK speaks, including a
  self-hosted one.
- **Search snippets** on every page and article, `@cmsSeo` for the head
  tags, a generated `sitemap.xml` and `robots.txt`.
- **Redirects**, a table the client edits, counted, run as global
  middleware so an old address with no route is still forwarded.
- **Scheduling** for pages: show from, hide after, judged at request time.
- **Preview links**: signed, expiring, `no-store`, for someone with no
  account.
- **Forms**: configured fields, a honeypot, an email with reply-to, an
  inbox in the panel with CSV download.
- **Photo folders and tags**, a move-to-folder bulk action, and a bulk
  delete that skips anything still in use and says so.
- **Analytics reports**: CSV download, email on demand, and a weekly email
  to a list the client keeps.
- **`gadya-cms:install`** now does everything between `composer require`
  and a working site, and can be run again.
- An options table for configuration that is not content, so it never
  lands in a revision.

### Changed

- Where a page lives is answered by `Gadya\Cms\Contracts\ResolvesPagePaths`
  (slug-based by default) instead of the host's route names.
- The fields at the top of a page's edit screen, the document config key,
  and the page type the locations nest under are configuration.
- Uploads fall back to GD when Imagick is missing, so a plain host still
  gets WebP. The doctor command reports the driver in use.
- `PageRegistry::isHidden()` covers both hidden and scheduled pages;
  `EditContext::showsDraft()` covers both editing and previewing.

## 0.1.0

First release.

- Pages, photos and revision history as Filament resources, with a live
  on-page editor for the words and pictures themselves.
- Draft and publish: nothing a client changes reaches the live site until
  she publishes it, and every publish can be restored.
- Look & feel, site details and a parent/child menu editor.
- First-party analytics with no cookies, no third-party script and no
  visitor address stored, with a live panel over a websocket when one is
  configured.
- Team invitations that send a single-use link rather than a password.
