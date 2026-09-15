<?php

namespace Gadya\Cms\Editor;

use Gadya\Cms\Content\EditableFields;
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

    private bool $enabled = false;

    private ?string $basePath = null;

    public function __construct(private readonly EditableFields $fields) {}

    public function boot(): void
    {
        $this->enabled = Gate::allows((string) config('gadya-cms.gate', 'manage-content'))
            && session(self::SESSION_KEY) === true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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

        return new HtmlString(sprintf(
            ' data-cms-path="%s" data-cms-type="%s"',
            e($path),
            e($this->fields->typeFor($path) ?? $type),
        ));
    }
}
