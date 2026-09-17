# The demo site

```bash
git clone https://github.com/gadyamedia/gadya-cms && cd gadya-cms
composer install
composer demo
```

Then open http://127.0.0.1:8000 and sign in at `/admin` as one of:

| Email | Password | Role |
| --- | --- | --- |
| admin@example.com | password | Administrator |
| editor@example.com | password | Editor |
| writer@example.com | password | Contributor |

Springfield Parties is a small party-hire site that uses every feature: pages with cards and a gallery, a hidden page and a scheduled one, three articles (one scheduled, one AI draft), enquiries in the inbox, a month of visitors, a redirect, and photos drawn on the spot so nothing is downloaded. The AI screens work once a key is pasted under Settings → AI.

It lives in `workbench/` (Testbench's convention) and is what the README screenshots come from. `composer build` rebuilds the database; `composer serve` uses Testbench's own server instead of PHP's.

# Scaffolding a page template

```bash
php artisan gadya-cms:make:page-template pages/types/menu --layout=layouts.site
```

writes a Blade template with `@cmsSeo`, `@editableFor`, every field in `pages.content_fields` already marked editable, and the sections loop (cards and gallery) written out. It warns about any field that is not yet in `editable_fields`. Pass `--force` to overwrite.
