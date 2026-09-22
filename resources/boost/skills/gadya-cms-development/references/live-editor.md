# The live editor

The admin is for structure. The page is for content: the client opens the real page with the editor switched on and changes the words and pictures in place, seeing exactly what a visitor will see.

## Layout

```blade
<head>
    @cmsSeo($page)
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled())
        @vite(['resources/css/editor.css', 'resources/js/editor.js'])
        @livewireStyles
    @endif
</head>
<body data-analytics-endpoint="{{ route('gadya-cms.events.store') }}">
    @yield('content')

    @cmsToolbar
    @if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled())
        @livewireScripts
    @endif
</body>
```

The two entry points import the package's own assets, so a public visitor never downloads a byte of editor:

```css
/* resources/css/editor.css */
@import "../../vendor/gadya/cms/resources/css/editor.css";
```

```js
// resources/js/editor.js
import '../../vendor/gadya/cms/resources/js/editor.js';
// resources/js/app.js
import '../../vendor/gadya/cms/resources/js/analytics.js';
```

## Marking content editable

```blade
@editableFor("pages.{$slug}")

<h1 @editable('heading')>{{ $page['heading'] }}</h1>
<p @editable('description', 'multiline')>{{ $page['description'] }}</p>
<img src="@siteImage($page['hero_image'])" @editable('hero_image', 'image')>

@foreach ($page['sections'] as $index => $section)
    <h2 @editable("sections.{$index}.title")>{{ $section['title'] }}</h2>
@endforeach

<a href="tel:..." @editableGlobal('phone')>{{ $site['phone'] }}</a>
```

| Directive | Purpose |
| --- | --- |
| `@editableFor($path)` | Set the path later `@editable` calls hang off |
| `@editable($field, $type)` | Mark an element editable, relative to that path |
| `@editableGlobal($path, $type)` | Mark an element editable by absolute path |
| `@siteImage($ref, $width)` / `@siteThumbnail($ref)` | Resolve a stored filename to a URL, at a width if given |
| `@siteSrcset($ref)` | Every responsive variant of a photo, for `srcset` |
| `@cmsToolbar` | The toolbar when editing; the preview bar when previewing |
| `@cmsSeo($page)` | The head tags - see [SEO](seo.md) |
| `@gadyaBuiltBy` | The "built by Gadya Media" badge, as the last thing in the footer - see below |

The directives render nothing for a visitor. For an editor they add `data-cms-path` and `data-cms-type`, which the editor's JavaScript turns into an inline editor (`text`), a side panel (`multiline` and `markdown`) or the photo library (`image`).

## Longer copy with a little structure

A legal page, a policy or a long answer needs a list, a bold phrase or a link, which plain text cannot hold. Make the field `markdown` and render it with `@cmsMarkdown`:

```blade
<div @editable("sections.{$index}.body", 'markdown')>@cmsMarkdown($section['body'])</div>
```

```php
'editable_fields' => [
    'pages.*.sections.*.body' => 'markdown',
],
```

The client writes `**bold**`, `[a link](https://...)` and lines starting with `- `; the side panel shows her those three reminders. For an editor the element carries its Markdown source, so the panel opens on what she wrote rather than on the rendered page, and after saving the server sends back the HTML, so the page shows exactly what visitors will. Raw HTML is stripped and `javascript:` links are refused, whoever typed them.

## The built-by badge

`@gadyaBuiltBy` goes last in the site's footer and puts the Gadya Media badge in its bottom-right corner, loading the badge script for you. It is drawn in the site's own ink:

- The colour is `brand.ink`, or `built_by.color` when that is set.
- The logo is recoloured to that colour by a CSS filter the package works out for it, then caches.

```blade
@gadyaBuiltBy
@gadyaBuiltBy(['color' => '#ffffff', 'align' => 'center', 'logo_height' => 24])
```

```php
'built_by' => [
    'enabled' => true,     // false renders nothing
    'color' => null,       // null follows brand.ink
    'filter' => null,      // null is worked out from the colour
    'logo_height' => 28,
    'align' => 'end',      // start, center or end
],
```

`gadya-cms:audit` flags a footer without it, and a hand-pasted `<gadya-built-by>` tag to replace.

## The allow-list

Only paths matched by `gadya-cms.editable_fields` can ever be written by an inline edit, whatever the browser sends:

```php
'editable_fields' => [
    'pages.*.heading' => 'text',
    'pages.*.description' => 'multiline',
    'pages.*.hero_image' => 'image',
    'pages.*.sections.*.items.*.title' => 'text',
    'phone' => 'text',
],
```

Keys of card lists are deliberately never renumbered when a card is hidden, because the template writes the index into the path.

## Locks

One person edits at a time. Opening the editor takes a lock for `gadya-cms.editor.lock_ttl_minutes`; a second editor sees who holds it and can read but not publish.

## Preview links

A page's row in the panel (and an article's edit screen) has **Share a preview**: a signed link, good for `gadya-cms.preview.expires_hours`, that shows the draft to someone with no account. Opening it marks that browser as previewing for the same period, so following links around the draft keeps working; the bar at the bottom says so and offers a way out. Previewed responses are sent `no-store`, so no cache in front of the site keeps a draft.

`EditContext::showsDraft()` is true for either an editor or a previewer; use it wherever you decide which document to render.
