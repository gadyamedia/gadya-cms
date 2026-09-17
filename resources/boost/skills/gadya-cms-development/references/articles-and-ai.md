# Articles and writing with AI

## Articles

**Content → Articles** is a writing screen: the draft gets the room, and the search snippet, photo, excerpt, publish date and aim sit in a rail beside it. Every save re-scores how findable the article is (keyword placement, heading structure, length, links, FAQ, photo) and lists what would raise the score.

An article is a draft until its status is *Published* and its date has passed. A date in the future schedules it. Questions and answers added to an article render under it and are published as FAQ structured data.

### On the public site

Leave `gadya-cms.blog.routes` on and the package serves `/blog` and `/blog/{slug}` in your own layout:

```php
'blog' => [
    'routes' => true,
    'prefix' => 'blog',
    'title' => 'Blog',
    'heading' => 'Party ideas, tips and news',
    'description' => 'Everything we have learned.',
    'layout' => 'layouts.site',   // must @yield('content') and accept $page and $site
    'per_page' => 12,
],
```

The templates use `cms-blog__*` and `cms-article__*` classes and no styles of their own; style them in your stylesheet, or publish and replace them:

```bash
php artisan vendor:publish --tag=gadya-cms-views
```

For your own templates, switch the routes off and read from `Gadya\Cms\Blog\BlogRepository`:

```php
$posts = $blog->live();            // paginated, published, dated in the past
$post = $blog->findLive($slug);    // or null
```

## Setting up AI

**Settings → AI** (administrators only):

- **Service** - Anthropic, OpenAI, Google Gemini, Groq, Mistral, DeepSeek, xAI, OpenRouter, a self-hosted Ollama, or any OpenAI-compatible endpoint. Whatever Laravel's AI SDK speaks.
- **Model** - a suggestion for the service, or any model name it offers.
- **API key** - stored encrypted with the application key and never shown again. A blank field on save keeps the key already stored.
- **Voice** - what the business does, who it is for, its tone, service area, phone number and house rules. Every article and snippet is written from this, so the more specific it is the less there is to fix.

**Test connection** sends the smallest possible prompt and reports what came back. Nothing in `.env` is required, and a key changed in the panel is used by the next request.

## Writing an article

**Content → Write with AI**: a topic, the search phrase to rank for, a place to mention, and what the reader wants (informational, commercial, local). The draft is written in the background - you can leave the page - and appears under *Recent requests* with a link. It is always a draft; nothing AI writes is published on its own.

The writer is told which pages on your site exist and asked to link to two or three of them, so it never invents an address. Suggestions come from `InternalLinkSuggester`, which scores visible pages and live articles by how many of the topic's words they share.

On an existing article, **Rewrite with AI** replaces the words but keeps the address.

## Snippets

Every page and article has **Write with AI** beside its search snippet, which writes the title and description from whatever the form currently holds. See [SEO](seo.md).

## Testing without a provider

```php
use Gadya\Cms\Ai\Agents\ArticleWriter;

ArticleWriter::fake([[
    'title' => '...', 'slug' => '...', 'meta_title' => '...', 'meta_description' => '...',
    'excerpt' => '...', 'body_html' => '<h2>...</h2>', 'hero_alt' => '...',
    'faq' => [['question' => '...', 'answer' => '...']], 'reading_time' => '5 min read',
]]);

ArticleWriter::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'birthday'));
```

`MetaWriter` and `ConnectionCheck` fake the same way. The settings still have to be saved first, because the SDK resolves the provider by the name the package registers.
