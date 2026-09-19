# SEO

## Head tags

```blade
<head>
    @cmsSeo($page)
</head>
```

`$page` is a page array from the site document or a `Post`. The directive renders the title, description, robots, canonical link, Open Graph and Twitter tags. Every value falls back: a page with no snippet gets its own title and description; a page with one gets exactly that.

```php
'seo' => [
    'site_name' => 'Fun On Us',
    'title_suffix' => ' | Fun On Us',
    'default_description' => 'What the site is about, for pages that say nothing.',
    'default_image' => 'logo.png',      // a photo library filename or a path under public/
],
```

In the panel, every page and article has an **In search results** section: a preview of the result, the title and description with live character counts, the photo used when shared, a canonical address, and a switch to keep the page out of search engines. **Write with AI** fills the two text fields from the page's own words.

Drafts and scheduled articles are always `noindex`.

## Sitemap and robots

`/sitemap.xml` lists every visible page (not hidden, not scheduled for later, not `noindex`) and every live article. `/robots.txt` disallows the panel and the editor and points at the sitemap.

```php
'seo' => [
    'sitemap' => true,
    'sitemap_extra' => ['/some-route-the-cms-does-not-know'],
    'robots' => true,
    'robots_disallow' => ['/admin', '/cms'],
],
```

A static `public/robots.txt` or `public/sitemap.xml` wins over these routes; delete the static file to let the package answer.

Every group in `robots.txt` carries a `Content-Signal` line (`seo.content_signals`), and the home page sends `Link` headers pointing at `llms.txt` and the sitemap (`seo.link_headers`). If the live site answers `/robots.txt` with 404 while it works locally with `php artisan serve`, the web server is intercepting it; see *Get found* in [search-and-readiness.md](search-and-readiness.md).

## Redirects

**Settings → Redirects** is a table of old addresses and where they go now, with a count of how often each is used. Paths are normalised (leading slash, no trailing slash, lower case) so any spelling of the old address matches. Only page requests are forwarded; a form posted to an old address is a bug to fix, not something to forward silently. The panel and editor paths can never be redirected away from.

The middleware is global, because an old address has no route for group middleware to hang off, and the table is cached until it changes.

Renaming a page in the panel writes its own redirect into the site document, separately, so the old slug keeps working.
