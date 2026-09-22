<?php

namespace Gadya\Cms\Editor;

use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Whether the current request is rendering the draft site for an editor,
 * and the `data-cms-*` attributes that turn a rendered element into a
 * live-editable one.
 */
class EditContext
{
    public const SESSION_KEY = 'gadya-cms.editing';

    public const PREVIEW_SESSION_KEY = 'gadya-cms.previewing-until';

    private bool $enabled = false;

    private bool $previewing = false;

    private ?string $basePath = null;

    /**
     * The draft, read once per request and only when a Markdown field
     * needs its source.
     *
     * @var array<string, mixed>|null
     */
    private ?array $draft = null;

    public function __construct(private readonly EditableFields $fields) {}

    public function boot(): void
    {
        $this->enabled = Gate::allows((string) config('gadya-cms.gate', 'manage-content'))
            && session(self::SESSION_KEY) === true;

        $until = session(self::PREVIEW_SESSION_KEY);
        $this->previewing = is_int($until) && $until > now()->getTimestamp();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the request is showing the draft to someone holding a
     * preview link, without the editor switched on.
     */
    public function isPreviewing(): bool
    {
        return $this->previewing;
    }

    /**
     * Whether the request should be rendered from the draft rather than the
     * live document, for either reason.
     */
    public function showsDraft(): bool
    {
        return $this->enabled || $this->previewing;
    }

    /**
     * Set the document path the next `attributes()` calls hang off, so a
     * template can say `@editable('heading')` rather than repeating the
     * whole `pages.about.heading` path on every element.
     */
    public function for(string $basePath): void
    {
        $this->basePath = $basePath;
    }

    public function attributes(string $field, string $type = 'text'): HtmlString
    {
        if (! $this->enabled || $this->basePath === null) {
            return new HtmlString('');
        }

        return $this->globalAttributes($this->basePath.'.'.$field, $type);
    }

    public function globalAttributes(string $path, string $type = 'text'): HtmlString
    {
        if (! $this->enabled || ! $this->fields->allows($path)) {
            return new HtmlString('');
        }

        $type = $this->fields->typeFor($path) ?? $type;

        /*
         * A Markdown field shows rendered HTML, so the editor cannot read
         * its source back off the page the way it reads plain text - the
         * bullets and links would be flattened on the first save. The
         * source travels with the element instead, for editors only.
         */
        $source = $type === 'markdown'
            ? sprintf(' data-cms-value="%s"', e((string) (Arr::get($this->draft(), $path) ?? '')))
            : '';

        return new HtmlString(sprintf(
            ' data-cms-path="%s" data-cms-type="%s"%s',
            e($path),
            e($type),
            $source,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(): array
    {
        return $this->draft ??= app(SiteContentRepository::class)->draft();
    }
}
