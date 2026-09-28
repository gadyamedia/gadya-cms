<?php

namespace Gadya\Cms\Forms\Builder;

use Closure;

/**
 * One kind of question a form can ask: what it is called in the builder,
 * how it is drawn, how its answer is checked and how that answer is kept.
 *
 * The package's own types are registered in FieldTypes; a site adds its
 * own the same way, from a service provider:
 *
 *     app(FieldTypes::class)->register(
 *         FieldType::make('vin', 'Vehicle VIN')
 *             ->group('Text')
 *             ->rules(fn (array $field): array => ['' => ['string', 'size:17']])
 *             ->view('forms.fields.vin'),
 *     );
 *
 * A custom view receives `$field`, `$id`, `$name`, `$value`, `$describedBy`,
 * `$invalid` and `$form`, and must draw an input named `$name`.
 */
final class FieldType
{
    private string $group = 'Other';

    private ?string $icon = null;

    private bool $input = true;

    private bool $choices = false;

    private bool $multiple = false;

    private bool $file = false;

    private ?string $view = null;

    /** @var Closure(array<string, mixed>): array<string, list<mixed>>|null */
    private ?Closure $rules = null;

    /** @var Closure(): list<mixed>|null */
    private ?Closure $settings = null;

    /** @var Closure(mixed, array<string, mixed>): mixed|null */
    private ?Closure $normalise = null;

    /** @var list<string> */
    private array $parts = [];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    /** Where it sits in the builder's list of field kinds. */
    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** Headings, paragraphs, dividers and step breaks ask nothing. */
    public function layout(): self
    {
        $this->input = false;

        return $this;
    }

    /** Answered by picking from the field's options. */
    public function choices(bool $multiple = false): self
    {
        $this->choices = true;
        $this->multiple = $multiple;

        return $this;
    }

    /** Answered with an upload, kept privately rather than in the enquiry. */
    public function file(): self
    {
        $this->file = true;

        return $this;
    }

    /**
     * An answer in several inputs (first and last name, the lines of an
     * address), posted as `key[part]`.
     *
     * @param  list<string>  $parts
     */
    public function parts(array $parts): self
    {
        $this->parts = $parts;

        return $this;
    }

    /**
     * The validation rules for an answer, keyed by the part they check -
     * '' for the answer itself. `required` is added by the form, and only
     * while the field is shown.
     *
     * @param  Closure(array<string, mixed>): array<string, list<mixed>>  $rules
     */
    public function rules(Closure $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * Extra settings for this kind of field in the builder, as Filament
     * components. Anything they save goes under `rules.*`.
     *
     * @param  Closure(): list<mixed>  $settings
     */
    public function settings(Closure $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * Turn a checked answer into what the inbox keeps.
     *
     * @param  Closure(mixed, array<string, mixed>): mixed  $normalise
     */
    public function normaliseUsing(Closure $normalise): self
    {
        $this->normalise = $normalise;

        return $this;
    }

    /** The Blade view that draws the input; the package draws its own types itself. */
    public function view(string $view): self
    {
        $this->view = $view;

        return $this;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function isInput(): bool
    {
        return $this->input;
    }

    public function hasChoices(): bool
    {
        return $this->choices;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function isFile(): bool
    {
        return $this->file;
    }

    /** @return list<string> */
    public function getParts(): array
    {
        return $this->parts;
    }

    public function getView(): ?string
    {
        return $this->view;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, list<mixed>>
     */
    public function rulesFor(array $field): array
    {
        return $this->rules === null ? ['' => ['nullable']] : ($this->rules)($field);
    }

    /** @return list<mixed> */
    public function settingsSchema(): array
    {
        return $this->settings === null ? [] : ($this->settings)();
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public function normalise(mixed $value, array $field): mixed
    {
        if ($this->normalise !== null) {
            return ($this->normalise)($value, $field);
        }

        return is_string($value) ? trim($value) : $value;
    }
}
