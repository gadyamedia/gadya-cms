<?php

namespace Gadya\Cms\Forms\Destinations;

use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A record in one of the site's own tables for each enquiry - a `Lead`,
 * say - described in config rather than written in PHP:
 *
 * ```php
 * 'destinations' => [
 *     'leads' => [
 *         'label' => 'Leads',
 *         'model' => App\Models\Lead::class,
 *         'map' => ['name' => 'name', 'email' => 'email', 'source' => '@utm_source', 'consented_at' => '@consent.at'],
 *         'defaults' => ['status' => 'new'],
 *     ],
 * ],
 * ```
 *
 * The map is the site's; the client can change it per form in the panel
 * (see DestinationMap). Attributes are set directly, not through the
 * model's `$fillable`: the developer who wrote the map chose them, and
 * the client can only map the attributes listed here. A default fills an
 * attribute the map left empty.
 */
class EloquentDestination implements FormDestination
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $map
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $model,
        private readonly array $map = [],
        private readonly array $defaults = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $model = $config['model'] ?? null;

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("The form destination [{$key}] needs a 'model' that is an Eloquent model.");
        }

        return new self(
            key: $key,
            label: (string) ($config['label'] ?? Str::headline($key)),
            model: $model,
            map: (array) ($config['map'] ?? []),
            defaults: (array) ($config['defaults'] ?? []),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The attributes the map names, then any other the model lets be
     * filled - so a column the developer forgot can still be mapped - but
     * never one set by a default alone.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        $fillable = rescue(fn (): array => (new $this->model)->getFillable(), [], report: false);
        $fields = [];

        foreach ([...array_keys($this->map), ...$fillable] as $attribute) {
            if (is_string($attribute) && ! isset($fields[$attribute]) && (isset($this->map[$attribute]) || ! array_key_exists($attribute, $this->defaults))) {
                $fields[$attribute] = is_string($this->map[$attribute] ?? null) ? 'From '.$this->map[$attribute] : Str::headline($attribute);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    public function map(): array
    {
        return $this->map;
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model: string, id: mixed}
     */
    public function handle(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): array
    {
        $override = $form->setting('destination_maps.'.$this->key);
        $allowed = array_keys($this->fields());
        $override = is_array($override) ? array_intersect_key($override, array_flip($allowed)) : null;

        $map = DestinationMap::merged($this->map, $override);
        $values = DestinationMap::resolve($map, $data, $context, array_column($form->schema()->inputs(), 'key'));

        /** @var Model $record */
        $record = new $this->model;

        foreach ($this->defaults as $attribute => $default) {
            if (($values[$attribute] ?? null) === null) {
                $values[$attribute] = $default;
            }
        }

        foreach ($values as $attribute => $value) {
            $values[$attribute] = match (true) {
                is_array($value) && ! $record->hasCast($attribute) => implode(', ', array_map(fn ($one): string => is_scalar($one) ? (string) $one : (string) json_encode($one), $value)),
                /*
                 * A moment goes in as a date, so a timestamp column gets
                 * one whether or not the model casts it.
                 */
                is_string($value) && is_string($map[$attribute] ?? null) && preg_match('/^@(submitted_at|consent(\.[a-z0-9_]+)?\.at)$/', $map[$attribute]) === 1 => Carbon::parse($value),
                default => $value,
            };
        }

        /* Left out rather than null, so the table's own default applies. */
        $record->forceFill(array_filter($values, fn (mixed $value): bool => $value !== null))->save();

        return ['model' => class_basename($record), 'id' => $record->getKey()];
    }
}
