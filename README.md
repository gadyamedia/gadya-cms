# Gadya CMS

[![Latest version](https://img.shields.io/packagist/v/gadya/cms.svg?style=flat-square)](https://packagist.org/packages/gadya/cms)
[![License](https://img.shields.io/packagist/l/gadya/cms.svg?style=flat-square)](LICENSE.md)

A client-editable CMS for Laravel and Filament, built around two ideas:

- **The admin is for structure.** Pages, photos, the menu, the site's colours
  and type, and who may work on it, all live in a Filament panel.
- **The page is for content.** The client opens the real page with the live
  editor switched on and edits the words and pictures in place, seeing exactly
  what a visitor will see.

Nothing either of those does reaches the public site until she presses
**Publish changes**, and every publish is a revision she can restore.

It also counts its own visitors, without Google and without a cookie banner.

## Requirements

- PHP 8.3+
- Laravel 13
- Filament 5

## Installation

```bash
composer require gadya/cms
php artisan migrate
php artisan gadya-cms:install
php artisan gadya-cms:editor
```

Register the plugin on a panel:

```php
use Gadya\Cms\Filament\GadyaCmsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            GadyaCmsPlugin::make(),
        ]);
}
```

Restrict the panel to people who may edit, by implementing
`Filament\Models\Contracts\FilamentUser` on your user model and defining the
two gates the package guards its writes with:

```php
Gate::define('manage-content', fn (User $user): bool => $user->canManageContent());
Gate::define('manage-users', fn (User $user): bool => $user->isAdministrator());
```

## Taking only the parts you want

Every feature is a switch, so a project with analytics or user management of
its own does not have to accept these to get the CMS:

```php
GadyaCmsPlugin::make()
    ->analytics(false)   // no dashboard or visitor figures
    ->team(false)        // no invitations; you manage users yourself
    ->brand(false)       // stock Filament chrome, no logo or palette
    ->navigationGroups(content: 'Website', appearance: 'Design')
```

Read the configuration back anywhere with `GadyaCmsPlugin::get()`, which
answers with defaults outside a panel rather than throwing.

## The site document

Content is one nested array — the *site document* — which your templates read
directly. Pages live one per row so Filament can list, sort and filter them;
everything else (the theme, the menu, the redirect table, global details) is
one row per top-level key. Both carry a `draft` and a `published` copy.

```blade
@editableFor("pages.{$slug}")

<h1 @editable('heading')>{{ $page['heading'] }}</h1>
<p @editable('description', 'multiline')>{{ $page['description'] }}</p>
<img src="@siteImage($page['hero_image'])" @editable('hero_image', 'image')>
```

`config/site.php` holds the document your application ships with, which is what
a fresh install is seeded from.

### Blade directives

| Directive | Purpose |
| --- | --- |
| `@editableFor($path)` | Set the path later `@editable` calls hang off |
| `@editable($field, $type)` | Mark an element editable, relative to that path |
| `@editableGlobal($path, $type)` | Mark an element editable by absolute path |
| `@siteImage($reference)` | Resolve a stored filename to a full-size URL |
| `@siteThumbnail($reference)` | Resolve a stored filename to a thumbnail URL |
| `@cmsToolbar` | Render the editor toolbar when edit mode is on |

## The live editor

Add the toolbar and its assets to your public layout:

```blade
@if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled())
    @vite(['resources/css/editor.css', 'resources/js/editor.js'])
    @livewireStyles
@endif

{{-- ... --}}

@cmsToolbar
```

where those two entry points import the package's own assets:

```css
@import "../../vendor/gadya/cms/resources/css/editor.css";
```

Only paths listed in `gadya-cms.editable_fields` can ever be written by an
inline edit, whatever the browser sends. `text` edits in place, `multiline`
opens a side panel, and `image` opens the photo library.

## Analytics

The dashboard shows where visitors came from and what they did, counted on
your own server from your own traffic. There is no third-party script, so
there is no cookie banner to earn:

- **No cookie is set and no address is stored.** A visitor is an HMAC of one
  with the date mixed in, so the same person gets a fresh identifier every
  day. Counting people once a day works; following anyone beyond it does not.
- **Country and town come from the CDN's own request headers** where one sits
  in front of the site, so nothing is looked up and nothing leaves the box.
- **Editors and bots are not counted**, and anything past the retention window
  is pruned by `gadya-cms:prune-analytics`.

Count anything worth counting from the page itself:

```blade
<a href="#book" data-analytics="booking_start" data-analytics-label="Brooklyn">Check availability</a>
```

Only names listed in `gadya-cms.analytics.events` are accepted, so a page
cannot invent a metric. Telephone links count themselves.

### Live updates

The live panel updates the moment someone lands, over a websocket. It is
optional: without one the panel polls, and every figure still comes from the
same tables, so all that is lost is immediacy.

```bash
composer require laravel/reverb
php artisan reverb:install
```

That is all. The package reads your application's broadcasting configuration
and points Filament's bundled Echo client at it, so there is no second config
file and no npm package. The channel is private and authorised by this package:
only someone who passes the CMS gate may listen, and the broadcast carries no
visitor identifier at all.

### The map

Point `gadya-cms.analytics.world_map` at a world map SVG whose paths carry
lowercase ISO country codes as classes and the dashboard draws a choropleth.
Leave it null and the same figures render as a list, so the package ships no
megabyte-sized asset nobody asked for.

## Team

**Settings → Team** lists everyone who may work on the site. An invitation is
an email with a single-use, expiring link to choose a password — no password
is ever sent. It carries a reset token and rides the panel's own password
reset flow.

Two rules hold whatever the request says, because a site that can be left with
no administrator is one somebody eventually locks themselves out of: nobody
can remove themselves, and the last administrator can be neither removed nor
demoted.

## Commands

| Command | Purpose |
| --- | --- |
| `gadya-cms:install` | Create the site and seed it from the shipped document |
| `gadya-cms:editor` | Create a user who can sign in to the CMS |
| `gadya-cms:doctor` | Check the server can process image uploads |
| `gadya-cms:export` | Write the published document out as a PHP array |
| `gadya-cms:import-legacy-media` | Index images already shipped with the site |
| `gadya-cms:import-legacy-content` | Import from a pre-Filament `site_contents` table |
| `gadya-cms:prune-analytics` | Delete analytics past the retention window |

Schedule the prune in `routes/console.php`:

```php
Schedule::command('gadya-cms:prune-analytics')->weekly();
```

## Configuration

```bash
php artisan vendor:publish --tag=gadya-cms-config
```

The published file documents every option: which document paths are editable,
which page and section types a client may create, the curated font list, the
brand palette and logo, the analytics retention and event allow-list, and the
roles that may work on the site.

## Credits

Built by [Gadya Media](https://github.com/gadyamedia). The analytics are
modelled on an earlier first-party implementation of the same idea.

## License

MIT. See [LICENSE.md](LICENSE.md).
