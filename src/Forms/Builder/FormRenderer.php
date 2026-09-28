<?php

namespace Gadya\Cms\Forms\Builder;

use Filament\Facades\Filament;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\PanelBrand;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Filament\Resources\Forms\FormResource;
use Gadya\Cms\Models\Form;
use Illuminate\Support\HtmlString;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Throwable;

/**
 * Draws a built form wherever it is placed: a page section, `[form:slug]`
 * in longer text, `<x-gadya-cms::form />`, the form's own page, an
 * iframe on another site, or a popup.
 *
 * The markup is plain and semantic - labels, fieldsets with legends,
 * errors tied to their inputs - with `cms-bform__*` classes to style. A
 * single-step form works without JavaScript; the small script that comes
 * with the first form on a page adds steps, show-and-hide, saving
 * progress and sending without a page load.
 */
class FormRenderer
{
    private int $instances = 0;

    public function __construct(
        private readonly SpamGuard $spam,
        private readonly FormLogic $logic,
    ) {}

    /**
     * @param  array{title?: string|null, intro?: string|null, class?: string|null, embed?: bool, section?: string|null, errors?: MessageBag|null, old?: array<string, mixed>|null, success?: string|null, preview?: bool}  $options
     */
    public function render(string|Form|null $form, array $options = []): HtmlString
    {
        $editor = app(EditContext::class);
        $slug = $form instanceof Form ? $form->slug : (string) $form;
        $form = $form instanceof Form ? $form : ($slug === '' ? null : Form::findLive($slug));

        if ($form === null) {
            return $editor->isEnabled()
                ? new HtmlString('<p class="cms-bform__missing">'.e($slug === '' ? 'No form has been chosen for this section yet.' : 'There is no published form called "'.$slug.'".').'</p>'.$this->editorTools(null, $options['section'] ?? null))
                : new HtmlString('');
        }

        try {
            return new HtmlString(view('gadya-cms::forms.builder.form', $this->viewData($form, $options))->render());
        } catch (Throwable $exception) {
            report($exception);

            return new HtmlString('');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function viewData(Form $form, array $options = []): array
    {
        $this->instances++;

        $schema = $form->schema();
        $embed = (bool) ($options['embed'] ?? false);
        $preview = (bool) ($options['preview'] ?? false);
        $errors = $options['errors'] ?? $this->sessionErrors($form->slug);
        $resume = session('gadya-cms.forms.resume.'.$form->slug);
        $resume = is_array($resume) ? $resume : null;
        $values = $this->values($schema, $options['old'] ?? null, $resume);
        $steps = $schema->steps($form->message('first_step'));
        $startStep = $resume !== null ? min((int) ($resume['step'] ?? 0), count($steps) - 1) : 0;

        if ($errors->any()) {
            $stepOf = $schema->stepOf();
            $firstError = collect($errors->keys())->map(fn (string $key): string => explode('.', $key)[0])->first(fn (string $key): bool => isset($stepOf[$key]));
            $startStep = $firstError !== null ? $stepOf[$firstError] : $startStep;
        }

        $editing = app(EditContext::class);
        $counts = ! $preview && ! $editing->showsDraft() && config('gadya-cms.analytics.enabled', true);

        return [
            'form' => $form,
            'schema' => $schema,
            'steps' => $steps,
            'multiStep' => count($steps) > 1,
            'startStep' => $startStep,
            'values' => $values,
            'bag' => $errors,
            'id' => 'cms-form-'.$form->slug.($this->instances > 1 ? '-'.$this->instances : ''),
            'title' => $options['title'] ?? null,
            'intro' => $options['intro'] ?? null,
            'extraClass' => trim(($form->setting('css_class') ?: '').' '.($options['class'] ?? '')),
            'embed' => $embed,
            'preview' => $preview,
            'action' => $preview ? '#' : ($embed ? route('gadya-cms.forms.embed.store', $form->slug) : route('gadya-cms.forms.store', $form->slug)),
            'saveUrl' => $preview || $embed ? null : route('gadya-cms.forms.save', $form->slug),
            'eventsUrl' => $counts ? route('gadya-cms.forms.events', $form->slug) : null,
            'seal' => $this->spam->seal($form->slug),
            'honeypot' => (string) config('gadya-cms.forms.honeypot', 'website'),
            'success' => $options['success'] ?? session('gadya-cms.form.'.$form->slug),
            'saved' => session('gadya-cms.form-saved.'.$form->slug),
            'resumeToken' => $resume['token'] ?? null,
            'turnstile' => $form->setting('turnstile') && ! $preview ? $this->spam->turnstileSiteKey() : null,
            'styles' => (bool) config('gadya-cms.forms.builder.styles', true) && $form->setting('styles', true),
            'theme' => $this->theme(),
            'types' => app(FieldTypes::class),
            'logic' => $this->logic,
            'editorTools' => $preview ? null : $this->editorTools($form, $options['section'] ?? null),
        ];
    }

    /**
     * What each field starts with: what they just typed (after an error),
     * what they saved to finish later, `?key=` in the address for a field
     * that takes it, or the field's default.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $resume
     * @return array<string, mixed>
     */
    public function values(FormSchema $schema, ?array $old = null, ?array $resume = null): array
    {
        $values = [];
        $old ??= (array) session()->getOldInput();
        $saved = is_array($resume['data'] ?? null) ? $resume['data'] : [];

        foreach ($schema->inputs() as $field) {
            $key = $field['key'];

            $values[$key] = match (true) {
                array_key_exists($key, $old) => $old[$key],
                array_key_exists($key, $saved) => $saved[$key],
                $field['prefill'] && request()->query($key) !== null => is_array(request()->query($key)) ? array_filter((array) request()->query($key), 'is_string') : mb_substr((string) request()->query($key), 0, 500),
                default => $field['default'] ?? null,
            };
        }

        return $values;
    }

    private function sessionErrors(string $slug): MessageBag
    {
        $errors = session('errors');

        return $errors instanceof ViewErrorBag ? $errors->getBag('gadya-cms.'.$slug) : new MessageBag;
    }

    /**
     * The site's colours, for the default stylesheet.
     *
     * @return array{accent: string, ink: string}
     */
    private function theme(): array
    {
        $tokens = rescue(fn (): array => app(PanelBrand::class)->tokens(), [], report: false);
        $hex = fn (mixed $value, string $fallback): string => is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : $fallback;

        return [
            'accent' => $hex($tokens['primary'] ?? null, '#1d4ed8'),
            'ink' => $hex($tokens['ink'] ?? null, '#111827'),
        ];
    }

    /**
     * For someone with the live editor on and the right to build forms: a
     * way to the form in the admin and, in a section, a way to swap it.
     */
    private function editorTools(?Form $form, ?string $section): ?HtmlString
    {
        $editor = app(EditContext::class);

        if (! $editor->isEnabled() || ! (auth()->user()?->can(Abilities::gate(Abilities::FORMS)) ?? false)) {
            return null;
        }

        $editUrl = $form === null ? null : rescue(fn (): string => FormResource::getUrl('edit', ['record' => $form], panel: (string) config('gadya-cms.panel', 'admin')), null, report: false);
        $choices = $section === null ? [] : rescue(fn (): array => Form::query()->forCurrentSite()->live()->orderBy('title')->pluck('title', 'slug')->all(), [], report: false);

        return new HtmlString(view('gadya-cms::forms.builder.editor-tools', [
            'form' => $form,
            'editUrl' => $editUrl,
            'section' => $section,
            'choices' => $choices,
            'panelUrl' => rescue(fn (): string => Filament::getPanel((string) config('gadya-cms.panel', 'admin'))->getUrl(), null, report: false),
        ])->render());
    }
}
