# Gadya CMS

[![Latest version](https://img.shields.io/packagist/v/gadya/cms.svg?style=flat-square)](https://packagist.org/packages/gadya/cms)
[![Tests](https://img.shields.io/github/actions/workflow/status/gadyamedia/gadya-cms/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/gadyamedia/gadya-cms/actions)
[![License](https://img.shields.io/packagist/l/gadya/cms.svg?style=flat-square)](LICENSE.md)

A client-editable CMS for Laravel and Filament, built around two ideas:

- **The admin is for structure.** Pages, photos, the menu, the site's colours and type, articles, redirects, enquiries, and who may work on it, all live in a Filament panel.
- **The page is for content.** The client opens the real page with the live editor switched on and edits the words and pictures in place, seeing exactly what a visitor will see.

Nothing either of those does reaches the public site until she presses **Publish changes**, and every publish is a revision she can restore.

It also counts its own visitors without Google or a cookie banner, keeps what people send through the site's forms, and - given an API key for any AI service - writes articles and search snippets in the business's own voice.

## What you get

| | |
| --- | --- |
| **Pages** | One row each, with a type, a status, sections, a photo, a search snippet, and dates to appear and disappear. |
| **Live editor** | `@editable` marks an element; the client edits it on the page. Text inline, prose in a side panel, photos from the library. One editor at a time, with a lock. |
| **Draft and publish** | Every change is a draft until published. Preview links show a draft to someone with no account. Revisions restore any publish. |
| **Photos** | Uploads resized, stripped of metadata, converted to WebP, optimised, with folders, tags, alt text, and "used on". Never deletable while on a page. |
| **Articles** | A writing screen with a findability score on every save. Scheduled publishing. Public routes in your own layout, or read them yourself. |
| **Writing with AI** | Provider, model and key chosen in the panel, stored encrypted. A voice for the business. Whole drafts, rewrites, and search snippets from the page's own words. Any provider Laravel's AI SDK speaks. |
| **SEO** | `@cmsSeo` renders every head tag with sensible fallbacks. A generated sitemap and robots file. A redirects table the client edits, with hit counts. |
| **Forms** | Configured fields, a honeypot, an email with reply-to, an inbox in the panel with CSV download. |
| **Analytics** | First-party, no cookie, no address stored. Live panel over a websocket. CSV download, a weekly email. |
| **Team** | Invitations by single-use link. Nobody can remove themselves; the last administrator stays. |
| **Look & feel** | Brand colours, a curated font list, the site's own logo in the panel. |

Every one of those is a switch on the plugin, so a project takes only what it wants.

## Requirements

PHP 8.3+, Laravel 13, Filament 5.

## Install

```bash
composer require gadya/cms
php artisan gadya-cms:install
```

```php
use Gadya\Cms\Filament\GadyaCmsPlugin;

$panel->login()->passwordReset()->plugins([GadyaCmsPlugin::make()]);
```

```php
Gate::define('manage-content', fn (User $user): bool => $user->role !== 'customer');
Gate::define('manage-users', fn (User $user): bool => $user->role === 'admin');
```

Then, in a template:

```blade
@editableFor("pages.{$slug}")

<h1 @editable('heading')>{{ $page['heading'] }}</h1>
<p @editable('description', 'multiline')>{{ $page['description'] }}</p>
<img src="@siteImage($page['hero_image'])" @editable('hero_image', 'image')>
```

That is the whole idea. [Installation](docs/installation.md) has the rest of the setup.

## Laravel Boost

The package ships a Boost guideline and a `gadya-cms-development` skill. In an application with `laravel/boost`, run `php artisan boost:update --discover` after installing and your agent learns how to work with the CMS.

## Documentation

- [Installation](docs/installation.md) - requirements, the plugin, gates, switches, scheduling, queues
- [The site document](docs/site-document.md) - how content is shaped and stored, where pages live, page fields, publishing
- [The live editor](docs/live-editor.md) - the layout, directives, the allow-list, locks, preview links
- [Articles and writing with AI](docs/articles-and-ai.md) - the writing screen, the public blog, setting up a provider, faking it in tests
- [SEO](docs/seo.md) - head tags, sitemap, robots, redirects
- [Forms](docs/forms.md) - configuration, the template, the inbox
- [Analytics](docs/analytics.md) - what is counted and how, events, live updates, reports
- [Photos and team](docs/media-and-team.md)
- [Commands, configuration and deploying](docs/commands.md)
- [Upgrading](docs/upgrading.md)

## Taking only the parts you want

```php
GadyaCmsPlugin::make()
    ->analytics(false)
    ->team(false)
    ->brand(false)
    ->blog(false)
    ->ai(false)
    ->redirects(false)
    ->forms(false)
    ->navigationGroups(content: 'Website', appearance: 'Design');
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

The package is tested on its own against a minimal host application, on PHP 8.3 and 8.4.

## Credits

Built by [Gadya Media](https://github.com/gadyamedia). The analytics and the article writer are modelled on earlier first-party implementations of the same ideas.

## License

MIT. See [LICENSE.md](LICENSE.md).
