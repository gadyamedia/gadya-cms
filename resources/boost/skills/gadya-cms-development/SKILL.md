---
name: gadya-cms-development
description: Build and extend a site on Gadya CMS - the site document, editable templates, controllers, articles and AI, SEO, forms, analytics, redirects and the package's configuration.
---

# Gadya CMS Development

## When to use this skill

Use it when adding or changing public templates or controllers, adding editable content, wiring forms, working on articles or the AI writer, configuring SEO, or changing `config/gadya-cms.php` in an application that uses `gadya/cms`. Full documentation lives in `vendor/gadya/cms/docs/`; read the relevant file there before implementing.

## Mental model

- **The site document** is one nested array: top-level keys (`announcement`, `phone`, `nav`, `theme`, `locations`, ...) plus `pages.{slug}` arrays. Pages are rows in `gadyacms_pages`; everything else is a row per key in `gadyacms_settings`. Each carries `draft` and `published` JSON.
- **The admin is for structure, the page is for content.** Filament edits types, sections, snippets, dates; the live editor edits words and photos in place.
- **Nothing is live until published.** `PublishSiteContent` copies draft → published, records a revision, flushes the forever-cached published document.
- **Configuration that is not content** (AI key, digest recipients) lives in `gadyacms_options` via `Gadya\Cms\Options\Options`, never in the document.

## Key classes

| Class | Use it for |
| --- | --- |
| `Content\SiteContentRepository` | `published()`, `draft()`, `forRequest()`, `saveDraft(array)` |
| `Content\PublicDocument` | `from($document)` strips hidden/scheduled pages from nav, cards, locations |
| `Content\PageRegistry` | `isHidden()`, `isScheduled()`, `publicPathFor($slug, $document)`, `resolveRedirect()`, `reservedSlugs()` |
| `Contracts\ResolvesPagePaths` | bind your own when pages live under a prefix (`pages.paths` config) |
| `Editor\EditContext` | `boot()`, `for($basePath)`, `isEnabled()`, `isPreviewing()`, `showsDraft()` |
| `Blog\BlogRepository` | `live()`, `findLive($slug)`, `uniqueSlug()` for your own article templates |
| `Ai\AiSettings` / `Ai\Prompter` | `isConfigured()`, `register()`; prompt any `Laravel\Ai` agent through the panel-chosen provider |
| `Seo\SeoHead` | `render($pageOrPost)` behind `@cmsSeo` |
| `Forms\FormDefinition` | the configured forms; `route('gadya-cms.forms.store', 'contact')` |
| `Analytics\AnalyticsReport` | `for($days)->headline()/daily()/topPages()/referrers()/events()` |
| `Filament\GadyaCmsPlugin` | `::get()->hasBlog()`, `hasAi()`, `hasForms()` ... |

## Controller pattern

```php
public function show(string $slug, SiteContentRepository $repository, PublicDocument $public, EditContext $editor, PageRegistry $registry): View
{
    $editor->boot();
    $site = $public->from($repository->forRequest());
    $page = $site['pages'][$slug] ?? null;

    if (! is_array($page)) {
        $destination = $registry->resolveRedirect($slug);
        abort_if($destination === null, 404);
        return redirect()->route('pages.show', $destination, 301);
    }

    abort_if($registry->isHidden($page) && ! $editor->showsDraft(), 404);
    $editor->for("pages.{$slug}");

    return view('pages.show', compact('page', 'site', 'slug'));
}
```

## Templates

- `@editableFor($path)` once, then `@editable($field, 'text'|'multiline'|'image')` on elements; `@editableGlobal('phone')` for top-level keys.
- Array indexes go into paths (`sections.{$index}.title`); `PublicDocument` preserves keys for that reason - never `array_values()` a card list before rendering.
- Images: store filenames, render with `@siteImage($ref)` / `@siteThumbnail($ref)`.
- Layout: `@cmsSeo($page)` in head; editor assets only when `EditContext::isEnabled()`; `@cmsToolbar` before `</body>`; `data-analytics-endpoint="{{ route('gadya-cms.events.store') }}"` on body; `data-analytics="booking_start"` on things worth counting (names must be in `analytics.events`).
- Forms: `<form method="POST" action="{{ route('gadya-cms.forms.store', 'contact') }}">@cmsForm('contact') ...</form> @cmsFormStatus('contact')`; define fields/rules/notify under `forms.forms.contact`.
- Blog templates ship as `gadya-cms::blog.index/show` extending `blog.layout`; style `cms-blog__*` / `cms-article__*` or publish the views with `--tag=gadya-cms-views`.

## Configuration rules

- Published config overrides package arrays wholesale: when you override `pages`, `navigation`, `analytics`, `users`, `seo`, `blog`, `forms`, copy every nested key.
- New editable paths → `editable_fields`. New page URL prefixes → a `ResolvesPagePaths` implementation in `pages.paths` and the slug in `pages.reserved_slugs` + `pages.route_excluded_slugs`.
- Page edit-screen fields → `pages.content_fields` (`text`, `textarea`, `image`).
- Plugin switches: `GadyaCmsPlugin::make()->blog(false)->ai(false)->forms(false)->redirects(false)->team(false)->analytics(false)->brand(false)`.

## Testing

- Seed content with `app(SiteContentRepository::class)->saveDraft($document)` then `app(PublishSiteContent::class)->handle()` and `flushPublishedCache()`.
- Fake AI: `ArticleWriter::fake([[...structured fields...]])`, `MetaWriter::fake([...])`, `ConnectionCheck::fake(['OK'])` after `app(AiSettings::class)->save([...])`.
- Filament tables: `Livewire::test(ListPosts::class)->selectTableRecords([...])->callAction(TestAction::make('x')->table()->bulk())`.
- Never mock `ImageCapabilities` without `driverName()`, `hasImagick()`, `supportsWebp()`.

## Commands

`gadya-cms:install`, `gadya-cms:editor`, `gadya-cms:doctor`, `gadya-cms:export`, `gadya-cms:import-legacy-content`, `gadya-cms:import-legacy-media`, `gadya-cms:prune-analytics`, `gadya-cms:analytics-digest`. Deploys: `migrate --force`, `filament:assets`, `optimize:clear`, a queue worker.
