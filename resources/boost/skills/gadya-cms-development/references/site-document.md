# The site document

Everything a visitor reads on a page lives in one nested array: the *site document*. Your templates read it directly, the live editor writes into it by path, and the panel edits the parts that are structure rather than words.

```php
// config/site.php - what a fresh install is seeded from
return [
    'announcement' => 'Now booking summer parties',
    'phone' => '(555) 010-2030',
    'nav' => [
        ['label' => 'Home', 'slug' => 'home'],
        ['label' => 'More', 'children' => [
            ['label' => 'Pricing', 'slug' => 'pricing'],
        ]],
    ],
    'theme' => ['primary' => '#9f12c7'],
    'pages' => [
        'home' => [
            'title' => 'Welcome',
            'type' => 'home',
            'heading' => 'Welcome to the site',
            'description' => 'A short introduction.',
            'hero_image' => 'hero.webp',
            'sections' => [
                ['type' => 'cards', 'title' => 'What we do', 'items' => [
                    ['title' => 'Parties', 'text' => 'We throw them.', 'image' => 'parties.webp'],
                ]],
            ],
        ],
    ],
];
```

The config key is `gadya-cms.document` (default `site`).

## How it is stored

- **Pages** are one row each in `gadyacms_pages`, so Filament can list, sort, filter and reorder them. The slug, title, type and status are real columns; the rest of the page is JSON.
- **Everything else** - the theme, the menu, the redirect table for renamed slugs, the global details - is one row per top-level key in `gadyacms_settings`.

Both carry a `draft` and a `published` copy. `SiteContentRepository` assembles the document from these rows:

```php
use Gadya\Cms\Content\SiteContentRepository;

$repository->published();   // what visitors see, cached until the next publish
$repository->draft();       // what the client is working on
$repository->forRequest();  // the draft when editing or previewing, else published
```

## Reading it in a controller

Pass the document through `PublicDocument` first: it removes hidden and scheduled pages from the menu, the cards and the location lists, so nothing on the site can point at a page that would 404.

```php
public function show(string $slug, SiteContentRepository $repository, PublicDocument $public, EditContext $editor, PageRegistry $registry): View
{
    $editor->boot();

    $site = $public->from($repository->forRequest());
    $page = $site['pages'][$slug] ?? abort(404);

    abort_if($registry->isHidden($page) && ! $editor->showsDraft(), 404);

    $editor->for("pages.{$slug}");

    return view('pages.show', compact('page', 'site', 'slug'));
}
```

## Where pages live

The package knows a page by its slug; only your application knows the route. `Gadya\Cms\Contracts\ResolvesPagePaths` answers "what is the public path for this slug?". The default answers `/` for the home page and `/slug` for everything else. A site with nested addresses binds its own:

```php
// config/gadya-cms.php
'pages' => [
    'home_slug' => 'home',
    'paths' => App\Support\Cms\LocationPagePaths::class,
],
```

```php
class LocationPagePaths implements ResolvesPagePaths
{
    public function publicPathFor(string $slug, array $document): string
    {
        foreach ($document['locations'] ?? [] as $key => $pageSlug) {
            if ($pageSlug === $slug) {
                return "/places/{$key}";
            }
        }

        return $slug === 'home' ? '/' : '/'.$slug;
    }
}
```

The panel, the sitemap, the editor and the internal-link suggestions all go through it.

## Page fields

The fields at the top of a page's edit screen are configuration:

```php
'pages' => [
    'content_fields' => [
        'heading' => ['label' => 'Heading', 'type' => 'text', 'max' => 120],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'rows' => 4],
        'hero_image' => ['label' => 'Main photo', 'type' => 'image'],
    ],
    'section_types' => ['cards', 'text-grid', 'gallery'],
    'creatable_types' => ['content', 'legal'],
    'reserved_slugs' => ['admin', 'cms', 'blog'],
],
```

Sections (`sections.*`) always carry a `type`, a `title`, a `subtitle`, `text`, and either `items` (title, text, image) or `images` for a gallery.

## Publishing and revisions

Nothing reaches the live site until **Publish changes** is pressed. Publishing copies every draft column onto its published twin, records the whole document as a revision, flushes the cache, and prunes revisions past `gadya-cms.revisions.keep`. Any revision can be restored from **Revision history**.

## Import and export

```bash
php artisan gadya-cms:export --path=storage/app/site.php   # the published document as a PHP array
php artisan gadya-cms:import-legacy-content                # from a pre-Filament site_contents table
```
