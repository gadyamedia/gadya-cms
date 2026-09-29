# More than one language

A site can be served in more than one language - Spanish alongside English is the usual case - with the machine writing a first draft of every page for a person to read through.

It is off by default. With one language enabled, nothing here runs and the site behaves exactly as it always did.

## Switching it on

```php
// config/gadya-cms.php
'locales' => [
    'default' => 'en',
    'enabled' => ['en', 'es'],
    'names' => ['en' => 'English', 'es' => 'Español'],
    'glossary' => ['Bounce Castle Deluxe'],          // never translated, on top of the panel's list
    'untranslated_keys' => ['theme', 'redirects', 'locations', 'blocks'],
    'chunk' => 5,                                     // pieces per background translation job
],
```

Run `php artisan migrate`: it adds one table, `gadyacms_translations`, and changes nothing that is already there.

## Addresses

The default language stays where it was; every other one lives under its own prefix:

| English | Spanish |
| --- | --- |
| `/` | `/es` |
| `/about` | `/es/about` |
| `/blog/foam-parties` | `/es/blog/foam-parties` |
| `/events/summer-camp` | `/es/events/summer-camp` |

The application's own routes need no change. The `LocaliseRequest` middleware takes the prefix off the path before the router sees it and makes it part of the request's base URL instead, so `/es/about` matches the same `/{slug}` route as `/about` - and every `url()`, `route()` and `redirect()` built while answering it carries `/es` by itself, so a visitor stays on the Spanish site however the template writes its links. Assets (`asset()`, `@vite`) still come from the root. `/en/about` is redirected to `/about` so every page has one address per language. Slugs are shared: a page is `/es/about`, not `/es/nosotros`.

A page may not take a language code (`es`) as its address.

## Templates

```blade
<html lang="@cmsLang">
<head>
    @cmsSeo($page)
</head>
<body>
    <x-gadya-cms::language-switcher />   {{-- or @cmsLanguageSwitcher(['label' => 'Idioma']) --}}
```

| Directive | Purpose |
| --- | --- |
| `@cmsLang` | The current language, for `<html lang>` |
| `<x-gadya-cms::language-switcher />` / `@cmsLanguageSwitcher` | Links to this page in every language; nothing on a one-language site. Classes: `cms-languages`, `cms-languages__link`, `cms-languages__link--current` |

`app(\Gadya\Cms\Localisation\Locales::class)` answers `current()`, `default()`, `enabled()`, `isTranslating()`, `url($path, $locale)` and `alternates($path)` for anything else a template needs.

Nothing else changes: `SiteContentRepository::forRequest()` already returns the document in the request's language, and articles, events, categories and tags are put into it as they are read.

## How it is stored

A translation is an *overlay* on the default language, one row per language per piece of content:

- a page: `pages.about`
- a top-level part of the document with words in it: `nav`, `announcement`, `footer`
- an article, event or term: `post:12`, `event:3`, `term:5`
- a built form: `form:4` - its title, questions, choices and words

Each row holds only the translated words, in the same shape as the original. When a page is drawn in Spanish, the English document is taken and each string the overlay has a non-empty translation for is replaced. So:

- **Anything not yet translated shows in English, never blank.**
- Structure, photos, slugs, dates, prices and links always come from the default language. A card added, a photo changed or a page hidden in English is the same in every language at once.
- Cards are matched by their `key`, so reordering them does not shuffle their translations.

`SiteContentRepository::publishedIn($locale)` and `draftIn($locale)` return the document in a language; they are for drawing pages and are never saved back.

## Editing in another language

Open a Spanish page with the editor on (or **Check on the page** under Settings → Languages). The toolbar says *Editing draft in Español* and links to the same page in the other languages. Words typed on a Spanish page are saved to its Spanish draft; photos changed there change in every language, because photos are shared.

Adding or removing cards is structure, and is shared too: a card added on the Spanish page appears on the English one, with its title in English until someone translates it.

Translations have a draft and a published copy like everything else, and **Publish changes** publishes them.

## Translating with AI

Wherever AI is set up (the site's own key under Settings → AI, or Gadya Media's through the portal):

- **Translate into Spanish** on a row of the pages, articles or events list.
- **Translate this page into Spanish** on the live editor's toolbar, on a Spanish page.
- **Translate the whole site** under **Settings → Languages**: everything not yet translated, or whose original has changed since, is translated in the background a few pieces per queued job.

A machine translation is always a **draft marked for review**. It is shown to editors on the page, flagged on the toolbar and on the Languages screen, and held back from publishing until someone presses **Mark as reviewed** - after that the next publish puts it live.

What must not change cannot change: HTML tags, Markdown link targets, web and email addresses, phone numbers, prices, the business's name and the glossary are swapped for markers before the model sees the text, and put back afterwards. A piece that comes back with a marker missing, doubled or invented is thrown away and the original words kept. The glossary is kept under Settings → Languages (and in `locales.glossary`).

Settings → Languages lists every page, menu and article with its state in each language: *Not translated*, *Machine translated - to review*, *Original changed since*, or *Translated*.

## Search engines and agents

- Every page's head carries `<link rel="alternate" hreflang>` for each language and `x-default` (the default language), and `og:locale`. The canonical address is the page in its own language.
- `/sitemap.xml` lists every address in every language, each with `xhtml:link` alternates.
- `/llms.txt` stays in the default language and adds a *Languages* section naming the others.
- A page asked for as `text/markdown` under `/es/` is answered in Spanish.
- Page views are counted under the address asked for (`/es/about`).

## Testing

```php
use Gadya\Cms\Ai\Agents\TranslationWriter;

config(['gadya-cms.locales.enabled' => ['en', 'es']]);

TranslationWriter::fake(fn (string $prompt): array => ['translations' => array_map(
    fn (array $item): array => ['id' => $item['id'], 'text' => 'ES '.$item['text']],
    json_decode(Str::after($prompt, "ITEMS:\n"), true)['items'],
)]);

app(TranslateContent::class)->translate('pages.about', 'es');
```

## Not translated yet

- Slugs: pages and articles keep their default-language address under the prefix.
- Revisions and **Revert** cover the default language only; a revert leaves translations as they are.
- The site search, the package's fixed template words (the blog's "Read next", form messages, the accessibility statement) and email are in the default language.
- `gadya-cms:export` / `import` and the takeout carry the default language only.
- Photos' alt text is shared across languages.
