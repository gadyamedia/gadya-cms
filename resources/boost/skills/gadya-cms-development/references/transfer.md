# Moving a site

```bash
php artisan gadya-cms:export --with-media                # storage/app/site-export-<date>.zip
php artisan gadya-cms:export --path=site.json            # content only, as JSON
php artisan gadya-cms:import site-export.zip             # update in place
php artisan gadya-cms:import site-export.zip --replace   # remove this site's content first
```

The export carries every page and setting (draft and published), articles, redirects, options, and the photo records; with `--with-media`, the photo files, thumbnails and variants too. Rows are matched by their natural key (slug, setting key, filename, old path), so an import can be repeated.

**Secrets never travel.** The AI key, the Search Console key and the PageSpeed key belong to one install and are neither exported nor removed by `--replace`.

`--array` writes the old format: the published document as a PHP array.

# Responsive photos

Every upload is written at the widths in `media.variants` (default 480, 960, 1600), narrower than the photo only, so a card never loads the 2400px original.

```blade
<img src="@siteImage($page['hero_image'], 1200)"
     srcset="@siteSrcset($page['hero_image'])"
     sizes="(max-width: 64rem) 100vw, 64rem"
     alt="...">
```

`@siteImage($ref, $width)` answers with the smallest variant at least that wide; `@siteSrcset` lists every variant and the original with its width. Legacy photos (shipped with the site) answer with the original alone, which is still valid.

```bash
php artisan gadya-cms:media-variants          # for photos uploaded before variants existed, and legacy public/ photos
php artisan gadya-cms:media-variants --force  # after changing the widths
```
