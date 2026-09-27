<?php

namespace Gadya\Cms\Localisation;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

/**
 * The site in its other languages.
 *
 * Design. The default language stays exactly where it always was: the
 * pages and settings rows, the posts and events tables, one document.
 * Another language is a sparse *overlay* on top of it, kept in
 * `gadyacms_translations` - one row per language per piece of content
 * (`pages.about`, a settings key such as `nav`, or `post:12` for an
 * article) holding only the words, in the same shape as the original.
 * Drawing a page in Spanish takes the English document and replaces each
 * string the overlay has a non-empty translation for; everything else -
 * the structure, the photos, the slugs, and any word nobody has translated
 * yet - comes from English, so a page is never blank and a new card or a
 * changed photo shows up in every language at once. Lists are matched by
 * their items' `key` where they have one, so reordering cards does not
 * shuffle their translations.
 *
 * Rows carry a draft and a published copy like the rest of the site, and
 * "Publish changes" publishes them too. A machine translation is written
 * to the draft only, marked `needs_review`, and held back from publishing
 * until a person has read it and marked it reviewed.
 *
 * The address of a page in another language is its default address under
 * a prefix (/es/about). The prefix is taken off by LocaliseRequest before
 * the router sees the request - and becomes the request's base URL - so
 * the application's own routes, and every url() and route() it builds
 * while answering, work unchanged in every language. With one language
 * enabled none of this runs and the site behaves exactly as before.
 */
class Translations
{
    /**
     * The words of each model worth translating, and the prefix its rows
     * are kept under.
     *
     * @var array<class-string<Model>, array{prefix: string, fields: list<string>}>
     */
    public const MODELS = [
        Post::class => ['prefix' => 'post', 'fields' => ['title', 'excerpt', 'content', 'hero_alt', 'meta_title', 'meta_description', 'faq', 'reading_time']],
        Event::class => ['prefix' => 'event', 'fields' => ['title', 'summary', 'body', 'hero_alt']],
        Term::class => ['prefix' => 'term', 'fields' => ['name', 'description']],
    ];

    /** @var array<string, Collection<string, Translation>> */
    private array $rows = [];

    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly Locales $locales,
        private readonly TranslatableText $text,
    ) {}

    /**
     * The document with a language's words laid over it.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function translate(array $document, string $locale, bool $draft = false): array
    {
        if ($locale === $this->locales->default() || $document === []) {
            return $document;
        }

        foreach ($this->rows($locale) as $key => $row) {
            $overlay = $this->overlayOf($row, $draft);

            if ($overlay === null) {
                continue;
            }

            if (str_starts_with($key, 'pages.')) {
                $slug = substr($key, 6);

                if (is_array($document['pages'][$slug] ?? null)) {
                    $document['pages'][$slug] = $this->merge($document['pages'][$slug], $overlay);
                }

                continue;
            }

            if ($this->isDocumentKey($key) && array_key_exists($key, $document)) {
                $document[$key] = $this->merge($document[$key], $overlay);
            }
        }

        return $document;
    }

    /**
     * A model's words in a language, laid over it in memory. The model is
     * marked clean afterwards, so a save during the request can never write
     * the translation over the original.
     */
    public function translateModel(Model $model, string $locale, bool $draft = false): Model
    {
        $key = $this->modelKey($model);

        if ($key === null || $locale === $this->locales->default()) {
            return $model;
        }

        $row = $this->rows($locale)->get($key);
        $overlay = $row === null ? null : $this->overlayOf($row, $draft);

        if (! is_array($overlay)) {
            return $model;
        }

        $base = $this->modelFields($model);
        $translated = $this->merge($base, $overlay);

        $model->forceFill(array_intersect_key($translated, $overlay));
        $model->syncOriginal();

        return $model;
    }

    /**
     * The row key a model's translation is kept under, e.g. `post:12`.
     */
    public function modelKey(Model $model): ?string
    {
        $prefix = self::MODELS[$model::class]['prefix'] ?? null;

        return $prefix === null || $model->getKey() === null ? null : $prefix.':'.$model->getKey();
    }

    /**
     * The translatable fields of a model, as they are in the default language.
     *
     * @return array<string, mixed>
     */
    public function modelFields(Model $model): array
    {
        $fields = [];

        foreach (self::MODELS[$model::class]['fields'] ?? [] as $field) {
            $fields[$field] = $model->getAttribute($field);
        }

        return $fields;
    }

    /**
     * Write one field of the document in a language, as the live editor
     * does: `pages.about.heading` goes into the `pages.about` row at
     * `heading`. The row's list items are first lined up with the
     * original's, so the index the page was drawn with is the one written.
     *
     * @param  array<string, mixed>  $document  The default-language draft
     */
    public function writeField(array $document, string $path, string $value, string $locale): void
    {
        [$key, $relative] = $this->split($path);
        $base = $this->baseFor($document, $key);
        $row = $this->row($key, $locale);

        if ($relative === '') {
            $overlay = $value;
        } else {
            $overlay = $this->overlayOf($row, true);
            $overlay = is_array($overlay) ? $this->realign($base, $overlay) : [];

            Arr::set($overlay, $relative, $value);
            $overlay = $this->stampKeys($overlay, $base, $relative);
        }

        $row->forceFill(['draft' => $overlay])->save();

        $this->forget($locale);
    }

    /**
     * Store a whole translation of one piece of content in the draft.
     *
     * @param  array<string, mixed>|string  $overlay
     */
    public function store(string $key, string $locale, array|string $overlay, bool $machine, ?string $sourceHash = null): Translation
    {
        $row = $this->row($key, $locale);

        $row->forceFill([
            'draft' => $overlay,
            'source' => $machine ? Translation::SOURCE_MACHINE : Translation::SOURCE_MANUAL,
            'needs_review' => $machine,
            'source_hash' => $sourceHash ?? $row->source_hash,
            'translated_at' => $machine ? now() : $row->translated_at,
            'reviewed_at' => $machine ? null : $row->reviewed_at,
            'reviewed_by' => $machine ? null : $row->reviewed_by,
        ])->save();

        $this->forget($locale);

        return $row;
    }

    /**
     * A person has read a translation and it may go live with the next
     * publish.
     */
    public function approve(string $key, string $locale, ?Authenticatable $user = null, ?string $sourceHash = null): void
    {
        $row = $this->find($key, $locale);

        if ($row === null) {
            return;
        }

        $row->forceFill([
            'needs_review' => false,
            'reviewed_at' => now(),
            'reviewed_by' => $user?->getAuthIdentifier(),
            'source_hash' => $sourceHash ?? $row->source_hash,
        ])->save();

        $this->forget($locale);
    }

    /**
     * Make every reviewed draft live. A machine translation nobody has
     * read yet keeps whatever was live before it.
     */
    public function publish(): int
    {
        $siteId = $this->siteContext->id();
        $published = 0;

        if ($siteId === null || ! $this->tableExists()) {
            return 0;
        }

        Translation::query()
            ->where('site_id', $siteId)
            ->where('needs_review', false)
            ->whereNotNull('draft')
            ->eachById(function (Translation $row) use (&$published): void {
                if ($row->draft === $row->published) {
                    return;
                }

                $row->forceFill(['published' => $row->draft])->save();
                $published++;
            });

        $this->rows = [];

        return $published;
    }

    /**
     * A page renamed keeps its translations.
     */
    public function rename(string $from, string $to): void
    {
        if (! $this->tableExists()) {
            return;
        }

        Translation::query()
            ->where('site_id', $this->siteContext->id())
            ->where('key', $from)
            ->update(['key' => $to]);

        $this->rows = [];
    }

    public function find(string $key, string $locale): ?Translation
    {
        return $this->rows($locale)->get($key);
    }

    /**
     * Every row in a language, keyed by what it translates. Read once per
     * request.
     *
     * @return Collection<string, Translation>
     */
    public function rows(string $locale): Collection
    {
        if (isset($this->rows[$locale])) {
            return $this->rows[$locale];
        }

        $siteId = $this->siteContext->id();

        if ($siteId === null) {
            return new Collection;
        }

        try {
            $rows = Translation::query()->where('site_id', $siteId)->where('locale', $locale)->get()->keyBy('key');
        } catch (QueryException) {
            $rows = new Collection;
        }

        return $this->rows[$locale] = $rows;
    }

    public function forget(?string $locale = null): void
    {
        if ($locale === null) {
            $this->rows = [];

            return;
        }

        unset($this->rows[$locale]);
    }

    /**
     * The overlay a row contributes: the draft for an editor, falling back
     * to what is live, and what is live for everyone else.
     *
     * @return array<array-key, mixed>|string|null
     */
    public function overlayOf(Translation $row, bool $draft): array|string|null
    {
        $overlay = $draft ? ($row->draft ?? $row->published) : $row->published;

        return is_array($overlay) || is_string($overlay) ? $overlay : null;
    }

    /**
     * Lay a translation over the original. Only strings are replaced, and
     * only by a non-empty translation; the original decides which keys and
     * items exist.
     */
    public function merge(mixed $base, mixed $overlay): mixed
    {
        if (is_string($base)) {
            return is_string($overlay) && trim($overlay) !== '' ? $overlay : $base;
        }

        if (! is_array($base) || ! is_array($overlay)) {
            return $base;
        }

        $overlay = $this->align($base, $overlay);

        foreach ($base as $key => $value) {
            if (! array_key_exists($key, $overlay) || (is_string($key) && in_array($key, TranslatableText::SKIPPED_KEYS, true))) {
                continue;
            }

            $base[$key] = $this->merge($value, $overlay[$key]);
        }

        return $base;
    }

    /**
     * Which row, and which path inside it, a document path belongs to: a
     * page's fields live in its own row, anything else in its top-level key's.
     *
     * @return array{0: string, 1: string}
     */
    public function split(string $path): array
    {
        $segments = explode('.', $path);

        if ($segments[0] === SiteContentRepository::PAGES_KEY && count($segments) > 2) {
            return [$segments[0].'.'.$segments[1], implode('.', array_slice($segments, 2))];
        }

        return [$segments[0], implode('.', array_slice($segments, 1))];
    }

    /**
     * The original a row translates, from the default-language document.
     *
     * @param  array<string, mixed>  $document
     */
    public function baseFor(array $document, string $key): mixed
    {
        if (str_starts_with($key, 'pages.')) {
            return $document['pages'][substr($key, 6)] ?? null;
        }

        return $document[$key] ?? null;
    }

    /**
     * Whether a top-level document key holds words at all.
     */
    public function isDocumentKey(string $key): bool
    {
        return $key !== SiteContentRepository::PAGES_KEY
            && ! str_contains($key, ':')
            && ! str_contains($key, '.')
            && ! in_array($key, (array) config('gadya-cms.locales.untranslated_keys', []), true);
    }

    /**
     * Put a list's translated items at the indexes of the original items
     * they belong to, matched by their `key`. Items with no key keep their
     * index.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $overlay
     * @return array<array-key, mixed>
     */
    private function align(array $base, array $overlay): array
    {
        $byKey = [];

        foreach ($overlay as $item) {
            if (is_array($item) && is_string($item['key'] ?? null)) {
                $byKey[$item['key']] = $item;
            }
        }

        if ($byKey === []) {
            return $overlay;
        }

        $aligned = [];

        foreach ($base as $index => $item) {
            $itemKey = is_array($item) ? ($item['key'] ?? null) : null;

            if (is_string($itemKey) && isset($byKey[$itemKey])) {
                $aligned[$index] = $byKey[$itemKey];
            } elseif (is_array($overlay[$index] ?? null) && ! isset($overlay[$index]['key'])) {
                $aligned[$index] = $overlay[$index];
            }
        }

        return $aligned;
    }

    /**
     * Copy every list item's `key` from the original into the translation,
     * wherever the translation has that item.
     *
     * @param  array<array-key, mixed>  $overlay
     * @return array<array-key, mixed>
     */
    public function withItemKeys(array $overlay, mixed $base): array
    {
        if (! is_array($base)) {
            return $overlay;
        }

        foreach ($overlay as $key => $value) {
            if (! is_array($value) || ! is_array($base[$key] ?? null)) {
                continue;
            }

            $value = $this->withItemKeys($value, $base[$key]);

            if (is_int($key) && is_string($base[$key]['key'] ?? null)) {
                $value['key'] = $base[$key]['key'];
            }

            $overlay[$key] = $value;
        }

        return $overlay;
    }

    /**
     * Line a whole overlay up with the original, dropping translations of
     * items that no longer exist.
     *
     * @param  array<array-key, mixed>  $overlay
     * @return array<array-key, mixed>
     */
    public function realign(mixed $base, array $overlay): array
    {
        if (! is_array($base)) {
            return $overlay;
        }

        $overlay = $this->align($base, $overlay);

        foreach ($overlay as $key => $value) {
            if (! array_key_exists($key, $base)) {
                unset($overlay[$key]);

                continue;
            }

            if (is_array($value)) {
                $overlay[$key] = $this->realign($base[$key], $value);
            }
        }

        return $overlay;
    }

    /**
     * Copy each list item's `key` into the translation along a path, so the
     * translation follows the item if the list is reordered later.
     *
     * @param  array<array-key, mixed>  $overlay
     * @return array<array-key, mixed>
     */
    private function stampKeys(array $overlay, mixed $base, string $relative): array
    {
        $segments = explode('.', $relative);
        $prefix = [];

        foreach ($segments as $segment) {
            $prefix[] = $segment;
            $path = implode('.', $prefix);
            $original = is_array($base) ? Arr::get($base, $path) : null;

            if (is_array($original) && is_string($original['key'] ?? null) && is_array(Arr::get($overlay, $path))) {
                Arr::set($overlay, $path.'.key', $original['key']);
            }
        }

        return $overlay;
    }

    private function row(string $key, string $locale): Translation
    {
        return Translation::query()->firstOrNew([
            'site_id' => $this->siteContext->id(),
            'locale' => $locale,
            'key' => $key,
        ]);
    }

    private function tableExists(): bool
    {
        return rescue(fn (): bool => Schema::hasTable('gadyacms_translations'), false, report: false);
    }
}
