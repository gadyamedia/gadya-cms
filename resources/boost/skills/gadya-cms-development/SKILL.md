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
| `Blog\BlogRepository` | `live()`, `findLive()`, `inTerm()`, `related()`, `termsInUse()`, `uniqueSlug()` |
| `Content\PageTypes` | `fieldsFor($type)`, `sectionTypesFor()`, `label()`, `creatable()` - fields differ per page type |
| `Content\SiteBlocks` | saved sections: `options()`, `section($key)`, `save($label, $section)` |
| `Events\EventCalendar` | `upcoming()`, `past()`, `findLive()`, `ics()`, `structuredData()` |
| `Search\SiteSearch` | `for($query)` over published pages and live articles |
| `Activity\Activity` | `record($event, $subject)`, `describe($event)` - the who-changed-what log |
| `Services\SchedulePublish` | `schedule($at)`, `isPending()`, `publishIfDue()` |
| `Support\Maintenance` | `isOn()`, `allows($request)`, `shareUrl()` - coming-soon mode |
| `Seo\LinkChecker` / `Models\BrokenLink` | links that lead nowhere, from visitors and from the site itself |
| `Forms\AutoReplies` | the thank-you email each form sends, written in the panel |
| `Ai\AiSettings` / `Ai\Prompter` | `isConfigured()`, `register()`; prompt any `Laravel\Ai` agent through the panel-chosen provider |
| `Seo\SeoHead` | `render($pageOrPost)` behind `@cmsSeo` |
| `Forms\FormDefinition` | the configured forms; `route('gadya-cms.forms.store', 'contact')` |
| `Analytics\AnalyticsReport` | `for($days)->headline()/daily()/topPages()/referrers()/events()` |
| `Filament\GadyaCmsPlugin` | `::get()->hasBlog()`, `hasAi()`, `hasForms()`, `hasSearch()` ... |
| `Access\Abilities` | `allows($user, 'publish')`, `forRole()`; gates are `gadya-cms.{ability}` |
| `Transfer\SiteExporter` / `SiteImporter` | move a site between installs; secrets never travel |
| `Search\SearchConsole` / `Search\PageSpeed` | Google data on the dashboard; both fake with `Http::fake()` |
| `Seo\AgentReadiness` / `Seo\LlmsText` / `Seo\Markdown` | the AI-assistant side of SEO |

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
- Images: store filenames, render with `@siteImage($ref, $width)`, `srcset="@siteSrcset($ref)"`, `style="@siteFocus($ref)"` when cropped; `@siteThumbnail($ref)` for lists.
- Other directives: `@cmsSearchForm`, `@cmsNewsletterForm`, `@cmsForm('contact')`, `@cmsFormStatus('contact')`, `@cmsSeo($page)`, `@cmsToolbar`. All take an optional array of overrides.
- Globals (announcement, phone, footer): `@editableGlobal('footer.tagline', 'multiline')`, listed in `gadya-cms.globals`.
- New page type: `php artisan gadya-cms:make:page-template pages/types/name` first, then adjust.
- Layout: `@cmsSeo($page)` in head; editor assets only when `EditContext::isEnabled()`; `@cmsToolbar` before `</body>`; `data-analytics-endpoint="{{ route('gadya-cms.events.store') }}"` on body; `data-analytics="booking_start"` on things worth counting (names must be in `analytics.events`).
- Forms: `<form method="POST" action="{{ route('gadya-cms.forms.store', 'contact') }}">@cmsForm('contact') ...</form> @cmsFormStatus('contact')`; define fields/rules/notify under `forms.forms.contact`.
- Blog templates ship as `gadya-cms::blog.index/show` extending `blog.layout`; style `cms-blog__*` / `cms-article__*` or publish the views with `--tag=gadya-cms-views`.

## Configuration rules

- Published config overrides package arrays wholesale: when you override `pages`, `navigation`, `analytics`, `users`, `seo`, `blog`, `forms`, copy every nested key.
- New editable paths → `editable_fields`. New page URL prefixes → a `ResolvesPagePaths` implementation in `pages.paths` and the slug in `pages.reserved_slugs` + `pages.route_excluded_slugs`.
- Page edit-screen fields → `pages.content_fields` (`text`, `textarea`, `image`).
- Plugin switches: `blog`, `ai`, `forms`, `redirects`, `search` (Google cards), `events`, `newsletter`, `team`, `analytics`, `brand`, `profile`, `unsavedChangesAlerts`, `navigationGroups`.
- Pages are soft-deleted: `Page::query()` hides the trash, `withTrashed()` shows it, `restoreToDraft()` brings one back editable. A slug a trashed page holds is restored and overwritten rather than colliding.
- Menus: the main one is `nav` in the document and in the form; more are configured under `navigation.menus` and read with `NavigationTree::forMenu($document, 'footer')`.
- Route parameters reach a controller method **by position, not by name** - never use `->defaults()` to pass a second value to a shared action; give each route its own method.
- Roles: `users.roles.{role} = ['label' => ..., 'abilities' => [...]]`; abilities are content, articles, photos, enquiries, publish, settings. The host's `canManageContent()` must include every role. Screens check `Abilities::gate(Abilities::X)` in `canAccess()`.
- The AI-assistant checks (`seo.llms`, `seo.markdown`, `seo.ai_crawlers`, `seo.organization`) are on by default; `gadya-cms:agent-ready` scores them.

## Testing

- Seed content with `app(SiteContentRepository::class)->saveDraft($document)` then `app(PublishSiteContent::class)->handle()` and `flushPublishedCache()`.
- Fake AI: `ArticleWriter::fake([[...structured fields...]])`, `MetaWriter::fake([...])`, `ConnectionCheck::fake(['OK'])` after `app(AiSettings::class)->save([...])`.
- Filament tables: `Livewire::test(ListPosts::class)->selectTableRecords([...])->callAction(TestAction::make('x')->table()->bulk())`.
- Never mock `ImageCapabilities` without `driverName()`, `hasImagick()`, `supportsWebp()`.
- Google services: `Http::fake(['oauth2.googleapis.com/token' => ..., 'www.googleapis.com/webmasters/v3/sites/*' => ..., PageSpeed::ENDPOINT.'*' => ...])`; a service-account key for tests is any RSA PEM in `{client_email, private_key}` JSON.
- The demo host under `workbench/` is a full example application: controller, layout, template, seeder.
- Filament form/schema actions are addressed with `TestAction::make('x')->schemaComponent('sectionKey')`; table bulk actions need `->selectTableRecords([...])` first.
- `actingAs` twice with *different* users in one test logs the session out (AuthenticateSession). Reuse one user, or split the test.
- SQLite refuses `HAVING` on an ungrouped query: use `whereHas` and keep `withCount` for the ordering.

## Commands

`gadya-cms:install`, `gadya-cms:editor`, `gadya-cms:doctor`, `gadya-cms:export` / `gadya-cms:import`, `gadya-cms:make:page-template`, `gadya-cms:media-variants`, `gadya-cms:import-legacy-content`, `gadya-cms:import-legacy-media`, `gadya-cms:prune-analytics`, `gadya-cms:analytics-digest`, `gadya-cms:search-console`, `gadya-cms:pagespeed`, `gadya-cms:agent-ready`. Deploys: `migrate --force`, `filament:assets`, `optimize:clear`, a queue worker.

## References

The full documentation ships inside this skill, so read the relevant file before implementing rather than guessing at an API:

- `references/installation.md` - requirements, the plugin, gates, switches, scheduling, queues
- `references/site-document.md` - how content is shaped and stored, `ResolvesPagePaths`, page fields, publishing
- `references/live-editor.md` - the layout, every directive, the allow-list, locks, preview links
- `references/articles-and-ai.md` - the writing screen, the public blog, providers, faking agents in tests
- `references/seo.md` - `@cmsSeo`, sitemap, robots, redirects
- `references/forms.md` - configuration, the template, the inbox
- `references/analytics.md` - what is counted, events, live updates, reports, `AnalyticsReport`
- `references/media-and-team.md` - the photo pipeline, folders, usage; invitations and the last-administrator rule
- `references/commands.md` - every Artisan command, config publishing, deploy steps
- `references/roles-and-globals.md` - abilities per role, the Everywhere screen
- `references/transfer.md` - moving a site, responsive photos
- `references/search-and-readiness.md` - Search Console, PageSpeed, the AI-readiness checks, the Get found page (live checks, DNS per domain, Forge robots.txt 404)
- `references/demo.md` - the demo site and the template generator
- `references/events-and-search.md` - the diary, the search box, the mailing list
- `references/operations.md` - trash, activity, scheduled publish, coming soon, broken links, replies
- `references/upgrading.md` - what each release asks of an application
