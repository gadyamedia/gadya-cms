# Upgrading

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
