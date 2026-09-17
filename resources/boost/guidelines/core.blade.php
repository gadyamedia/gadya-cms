## Gadya CMS

`gadya/cms` is a client-editable CMS for Filament 5: pages live in a nested *site document* (draft + published copies), the client edits words and photos on the real page with the live editor, and nothing reaches the public site until she presses **Publish changes**. It also ships articles (optionally written by AI), SEO head tags, a sitemap, redirects, a forms inbox, first-party analytics and team invitations.

- Activate the `gadya-cms-development` skill before touching templates, controllers, `config/gadya-cms.php`, or anything under `Gadya\Cms\`.
- Templates read the document as an array (`$page['heading']`, `$site['phone']`) and mark editable elements with directives, never by writing to the database directly:

@verbatim
<code-snippet name="An editable page template" lang="blade">
@editableFor("pages.{$slug}")
<h1 @editable('heading')>{{ $page['heading'] }}</h1>
<p @editable('description', 'multiline')>{{ $page['description'] }}</p>
<img src="@siteImage($page['hero_image'])" @editable('hero_image', 'image')>
</code-snippet>
@endverbatim

- A controller resolves the document through `SiteContentRepository::forRequest()` and `PublicDocument::from()`, calls `EditContext::boot()` first, and 404s a page when `PageRegistry::isHidden($page)` unless `EditContext::showsDraft()`.
- Any new editable path must be added to `gadya-cms.editable_fields`; the live editor refuses everything else.
- The published `config/gadya-cms.php` overrides package arrays wholesale (top-level merge), so copy every nested key of an array you override.
- Put `@cmsSeo($page)` in `<head>` instead of hand-written title/description tags; `@cmsToolbar` before `</body>`.
- Content changes (pages, menu, photos) live in the database, not in git; test them with `$this->publishDocument([...])`-style helpers, never by editing `config/site.php` in production.
