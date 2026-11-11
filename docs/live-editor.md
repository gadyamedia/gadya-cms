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
| `@cmsSection($section, $index)` | First line inside the section loop: draws the sections the package knows (a Form section) - see [Forms](forms.md) |

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

## Laying out a page from the page

The client adds, moves and removes whole sections herself, in the designs the site offers, without the admin. Put the controls in the section loop, and mark the loop as a region so a change redraws it in place:

```blade
<x-gadya-cms::region name="sections">
    <x-gadya-cms::add-section :position="0" />

    @foreach ($page['sections'] as $sectionIndex => $section)
        @cmsSection($section, $sectionIndex)
        <section>
            <x-gadya-cms::section-tools :index="$sectionIndex" :first="$loop->first" :last="$loop->last" />
            ...the section in its design...
        </section>
        <x-gadya-cms::add-section :position="$sectionIndex + 1" />
    @endforeach
</x-gadya-cms::region>
```

- `<x-gadya-cms::add-section>` is "+ Add a section here". It opens the design picker the toolbar draws, which shows each design in `pages.section_designs` (photo cards, text boxes, list, photo gallery, form) that the page's type allows. A new section lands at that spot with one sample item to type over. A page that never had sections, such as a home page, gets its first.
- `<x-gadya-cms::section-tools>` moves a section up or down, or removes it.
- A gallery grows one photo at a time: `<x-gadya-cms::add-photo :section="$sectionIndex" />` adds an empty slot, which the photo library fills. Draw an empty slot as something to click while editing (`@editable("sections.{$sectionIndex}.images.{$imageIndex}", 'image')`) and skip it for visitors.
- Every change goes to the draft, and is refused while someone else holds the [lock](#locks).

## Links the client adds

A waiver, a social page, a booking form elsewhere: the client puts links where she wants them.

```blade
{{-- In the header and the footer: her own list of links, typed on the page. --}}
<x-gadya-cms::links list="footer" :site="$site" link-class="footer-link" />

{{-- On any card, text box or list item: an optional button. --}}
<x-gadya-cms::item-button :item="$item" path="sections.{{ $sectionIndex }}.items.{{ $itemIndex }}" class="button" />
```

A list is kept at `links.{list}` in the document; "+ Add link" adds one, and its words and address are typed in place. An item button keeps `link_label` and `link_url` on the item. Both are always editable, whatever `editable_fields` lists. Visitors see a link only once it has words and an address, and only a web address, a path on the site, an anchor, an email or a phone link is drawn; a bare domain gets `https://`, and a `javascript:` address is never drawn (`Gadya\Cms\Support\SafeLink`). Links that leave the site open in a new tab.

## Without a reload

After a change of shape - a section, card, photo slot or link added, moved or removed, a photo chosen - the editor fetches the page again and swaps in only the regions whose markup changed, so the client stays where she was. Wrap any part of the page a change can touch in `<x-gadya-cms::region name="...">` (it takes no room in the layout, and visitors get its contents alone); `<x-gadya-cms::links>` is a region of its own. A page with no regions reloads instead, and comes back to the same scroll position.

A swapped region is a fresh piece of the page: the editor sets up its editing, and announces it so the site's own scripts can wake anything in it that they set up when the page loaded.

```js
window.addEventListener('cms:region-replaced', ({ detail }) => {
    detail.element.querySelectorAll('[data-reveal]').forEach((element) => element.classList.add('is-visible'));
});
```

While editing, words or a photo inside a link are for editing: clicking them never follows the link, and clicking words inside an editable photo's area types into them rather than opening the photo library.

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
