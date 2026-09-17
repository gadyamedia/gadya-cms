# Changelog

All notable changes to `gadya/cms` are documented here.

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
