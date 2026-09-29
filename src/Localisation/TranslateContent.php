<?php

namespace Gadya\Cms\Localisation;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Jobs\TranslateSiteContent;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Everything on the site that can be translated, how far along each piece
 * is in a language, and the machine translation of any of them into a
 * draft for a person to check.
 *
 * A piece is a page (`pages.about`), a top-level part of the document with
 * words in it (`nav`, `announcement`, `footer`), or an article, event,
 * category or tag (`post:12`, `event:3`, `term:5`).
 */
class TranslateContent
{
    public const STATUS_MISSING = 'missing';

    public const STATUS_REVIEW = 'review';

    public const STATUS_OUTDATED = 'outdated';

    public const STATUS_TRANSLATED = 'translated';

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly Translations $translations,
        private readonly TranslatableText $text,
        private readonly Translator $translator,
        private readonly SiteContext $siteContext,
        private readonly Locales $locales,
    ) {}

    /**
     * Every translatable piece, with a label a person would recognise.
     *
     * @return array<string, array{label: string, kind: string, path: string|null}>
     */
    public function units(): array
    {
        $document = $this->repository->draft();
        $units = [];

        foreach ($document[SiteContentRepository::PAGES_KEY] ?? [] as $slug => $page) {
            if (is_array($page)) {
                $units['pages.'.$slug] = [
                    'label' => (string) ($page['title'] ?? $slug),
                    'kind' => 'Page',
                    'path' => rescue(fn (): string => app(PageRegistry::class)->publicPathFor((string) $slug, $document), null, report: false),
                ];
            }
        }

        foreach ($document as $key => $value) {
            if ($this->translations->isDocumentKey((string) $key) && $this->text->leaves($value) !== []) {
                $units[(string) $key] = ['label' => str((string) $key)->headline()->toString(), 'kind' => 'Everywhere', 'path' => null];
            }
        }

        foreach ($this->models() as $model) {
            $key = (string) $this->translations->modelKey($model);

            $units[$key] = match (true) {
                $model instanceof Post => ['label' => (string) $model->title, 'kind' => 'Article', 'path' => $model->publicPath()],
                $model instanceof Event => ['label' => (string) $model->title, 'kind' => 'Event', 'path' => $model->publicPath()],
                $model instanceof Form => ['label' => (string) $model->title, 'kind' => 'Form', 'path' => $model->setting('public_page', true) ? '/'.trim((string) config('gadya-cms.forms.builder.path', 'forms'), '/').'/'.$model->slug : null],
                default => ['label' => (string) $model->getAttribute('name'), 'kind' => 'Category or tag', 'path' => null],
            };
        }

        return $units;
    }

    /**
     * The words of a piece in the default language, keyed by their path
     * inside it.
     *
     * @return array<string, string>
     */
    public function leaves(string $key): array
    {
        return $this->text->leaves($this->source($key));
    }

    /**
     * The piece itself, as the default language has it.
     */
    public function source(string $key): mixed
    {
        if (str_contains($key, ':')) {
            $model = $this->model($key);

            return $model === null ? null : $this->translations->modelFields($model);
        }

        return $this->translations->baseFor($this->repository->draft(), $key);
    }

    public function hash(string $key): string
    {
        return $this->text->hash($this->leaves($key));
    }

    public function status(string $key, string $locale): string
    {
        $row = $this->translations->find($key, $locale);
        $overlay = $row === null ? null : $this->translations->overlayOf($row, true);

        return match (true) {
            $overlay === null || $overlay === [] || $overlay === '' => self::STATUS_MISSING,
            $row->needs_review => self::STATUS_REVIEW,
            $row->source_hash !== null && $row->source_hash !== $this->hash($key) => self::STATUS_OUTDATED,
            default => self::STATUS_TRANSLATED,
        };
    }

    /**
     * The pieces a "translate the whole site" should do: those with no
     * translation, and those whose original has changed since.
     *
     * @return list<string>
     */
    public function pending(string $locale): array
    {
        return array_values(array_filter(
            array_keys($this->units()),
            fn (string $key): bool => in_array($this->status($key, $locale), [self::STATUS_MISSING, self::STATUS_OUTDATED], true)
                && $this->leaves($key) !== [],
        ));
    }

    /**
     * Machine-translate one piece into the draft, marked for review.
     * Pieces of text that did not come back whole keep whatever
     * translation they had, or show the original.
     *
     * @return array{translated: int, kept: int}
     */
    public function translate(string $key, string $locale): array
    {
        $source = $this->source($key);
        $leaves = $this->text->leaves($source);

        if ($leaves === [] || ! $this->locales->isEnabled($locale) || $locale === $this->locales->default()) {
            return ['translated' => 0, 'kept' => 0];
        }

        $translated = $this->translator->translate($leaves, $locale);

        if (array_key_exists('', $translated)) {
            $overlay = $translated[''];
        } else {
            $row = $this->translations->find($key, $locale);
            $existing = $row === null ? null : $this->translations->overlayOf($row, true);
            $overlay = is_array($existing) ? $this->translations->realign($source, $existing) : [];

            foreach ($translated as $path => $text) {
                Arr::set($overlay, $path, $text);
            }

            $overlay = $this->translations->withItemKeys($overlay, $source);
        }

        if ($translated !== []) {
            $this->translations->store($key, $locale, $overlay, machine: true, sourceHash: $this->text->hash($leaves));
        }

        return ['translated' => count($translated), 'kept' => count($leaves) - count($translated)];
    }

    /**
     * A person has read the translation of a piece.
     */
    public function approve(string $key, string $locale, ?Authenticatable $user = null): void
    {
        $this->translations->approve($key, $locale, $user, $this->hash($key));
    }

    /**
     * Put translation of these pieces on the queue, a few to a job, so a
     * whole site is done in the background without one slow page holding
     * up the rest.
     *
     * @param  list<string>  $keys
     */
    public function queue(array $keys, string $locale, ?Authenticatable $user = null): int
    {
        $size = max(1, (int) config('gadya-cms.locales.chunk', 5));

        foreach (array_chunk($keys, $size) as $chunk) {
            TranslateSiteContent::dispatch($chunk, $locale, $user?->getAuthIdentifier());
        }

        return count($keys);
    }

    /**
     * @return list<Model>
     */
    private function models(): array
    {
        $siteId = $this->siteContext->id();
        $models = [];

        foreach (array_keys(Translations::MODELS) as $class) {
            $records = $this->locales->inDefault(fn () => rescue(fn () => $class::query()->where('site_id', $siteId)->orderBy('id')->get(), collect(), report: false));

            foreach ($records as $model) {
                $models[] = $model;
            }
        }

        return $models;
    }

    private function model(string $key): ?Model
    {
        [$prefix, $id] = array_pad(explode(':', $key, 2), 2, null);

        foreach (Translations::MODELS as $class => $definition) {
            if ($definition['prefix'] === $prefix) {
                /* The original, whatever language the request asking is in. */
                return $this->locales->inDefault(fn (): ?Model => $class::query()->where('site_id', $this->siteContext->id())->find($id));
            }
        }

        return null;
    }

    /**
     * Whether a row exists for any piece in this language that a person
     * still has to read.
     */
    public function awaitingReview(string $locale): int
    {
        return Translation::query()
            ->where('site_id', $this->siteContext->id())
            ->where('locale', $locale)
            ->where('needs_review', true)
            ->count();
    }
}
