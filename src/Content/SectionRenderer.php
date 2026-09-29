<?php

namespace Gadya\Cms\Content;

use Closure;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Forms\Builder\FormRenderer;
use Illuminate\Support\HtmlString;

/**
 * Sections the package knows how to draw by itself, so a client can put
 * one on any page without a developer writing its template.
 *
 * A site's own section loop asks here first with one line -
 * `@cmsSection($section, $index)` - and anything the package draws is
 * drawn and skipped over; everything else falls through to the site's own
 * markup, exactly as before. Today that is the "Form" section; a site or
 * another package can add more with `extend()`.
 */
class SectionRenderer
{
    /** @var array<string, Closure(array<string, mixed>, string|null): HtmlString> */
    private array $renderers = [];

    public function __construct()
    {
        $this->extend('form', fn (array $section, ?string $path): HtmlString => $this->form($section, $path));
    }

    /**
     * @param  Closure(array<string, mixed>, string|null): HtmlString  $renderer  Given the section and its document path
     */
    public function extend(string $type, Closure $renderer): void
    {
        $this->renderers[$type] = $renderer;
    }

    /**
     * The section kinds drawn here and offered on every page.
     *
     * @return list<string>
     */
    public function types(): array
    {
        if (! config('gadya-cms.forms.builder.enabled', true) || ! config('gadya-cms.forms.builder.sections', true)) {
            return array_values(array_diff(array_keys($this->renderers), ['form']));
        }

        return array_keys($this->renderers);
    }

    public function handles(mixed $section): bool
    {
        return is_array($section) && is_string($section['type'] ?? null) && in_array($section['type'], $this->types(), true);
    }

    /**
     * The section drawn, or null when it is the site's to draw.
     *
     * @param  array<string, mixed>|mixed  $section
     */
    public function render(mixed $section, int|string|null $index = null): ?HtmlString
    {
        if (! $this->handles($section)) {
            return null;
        }

        $base = app(EditContext::class)->basePath();
        $path = $base !== null && $index !== null ? $base.'.sections.'.$index : null;

        return rescue(fn (): HtmlString => ($this->renderers[$section['type']])($section, $path), new HtmlString(''), report: true);
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function form(array $section, ?string $path): HtmlString
    {
        $editor = app(EditContext::class);
        $relative = $path === null ? null : substr($path, strlen((string) $editor->basePath()) + 1);
        $title = trim((string) ($section['title'] ?? ''));
        $intro = trim((string) ($section['text'] ?? ''));
        $form = app(FormRenderer::class)->render((string) ($section['form'] ?? ''), ['section' => $path]);

        if ($form->toHtml() === '' && ! $editor->isEnabled()) {
            return new HtmlString('');
        }

        $html = '<section class="cms-section cms-section--form">';

        if ($title !== '' || ($editor->isEnabled() && $relative !== null)) {
            $html .= '<h2 class="cms-section__title"'.($relative !== null ? $editor->attributes($relative.'.title') : '').'>'.e($title).'</h2>';
        }

        if ($intro !== '' || ($editor->isEnabled() && $relative !== null)) {
            $html .= '<p class="cms-section__intro"'.($relative !== null ? $editor->attributes($relative.'.text', 'multiline') : '').'>'.nl2br(e($intro)).'</p>';
        }

        return new HtmlString($html.$form->toHtml().'</section>');
    }
}
