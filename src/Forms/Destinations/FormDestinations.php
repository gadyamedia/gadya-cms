<?php

namespace Gadya\Cms\Forms\Destinations;

use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every destination a form can hand its enquiries on to, and the running
 * of them.
 *
 * Destinations come from `forms.builder.destinations` in config - a
 * model to create, described in an array, or the class of one the site
 * wrote - and from `register()`, for a package or a service provider.
 * Config is read each time, so a destination added there is offered
 * straight away.
 *
 * Running them is the last thing before the emails go: the enquiry is
 * already safe in the inbox, each destination runs on its own, and one
 * that fails is reported and noted on the enquiry - the others still
 * run, and the visitor never knows.
 */
class FormDestinations
{
    /** @var array<string, FormDestination> */
    private array $registered = [];

    public function register(FormDestination $destination): void
    {
        $this->registered[$destination->key()] = $destination;
    }

    /**
     * @return array<string, FormDestination>
     */
    public function all(): array
    {
        $destinations = [];

        foreach ((array) config('gadya-cms.forms.builder.destinations', []) as $key => $config) {
            $destination = rescue(fn (): ?FormDestination => $this->fromConfig((string) $key, $config), null, report: true);

            if ($destination !== null) {
                $destinations[$destination->key()] = $destination;
            }
        }

        return [...$destinations, ...$this->registered];
    }

    public function get(string $key): ?FormDestination
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return array_map(fn (FormDestination $destination): string => $destination->label(), $this->all());
    }

    /**
     * Hand an enquiry on to each destination the form names, and note how
     * each went on the enquiry (`meta.destinations`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    public function run(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): array
    {
        $chosen = array_values(array_unique(array_filter((array) $form->setting('destinations', []), 'is_string')));

        if ($chosen === []) {
            return [];
        }

        $results = [];

        foreach ($chosen as $key) {
            $destination = $this->get($key);

            if ($destination === null) {
                $results[$key] = ['ok' => false, 'at' => now()->toIso8601String(), 'error' => 'There is no destination called "'.$key.'" any more.'];

                continue;
            }

            try {
                $result = $destination->handle($form, $submission, $data, $context);
                $results[$key] = array_filter([
                    'ok' => true,
                    'at' => now()->toIso8601String(),
                    'label' => $destination->label(),
                    'result' => $this->describe($result),
                ], fn ($value): bool => $value !== null);
            } catch (Throwable $exception) {
                report($exception);

                $results[$key] = [
                    'ok' => false,
                    'at' => now()->toIso8601String(),
                    'label' => $destination->label(),
                    'error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 300),
                ];
            }
        }

        rescue(fn () => $submission->forceFill(['meta' => [...(array) ($submission->meta ?? []), 'destinations' => $results]])->saveQuietly(), report: true);

        return $results;
    }

    private function fromConfig(string $key, mixed $config): ?FormDestination
    {
        if (is_string($config) && is_subclass_of($config, FormDestination::class)) {
            return app($config);
        }

        if (! is_array($config)) {
            return null;
        }

        if (is_string($config['class'] ?? null) && is_subclass_of($config['class'], FormDestination::class)) {
            return app($config['class'], ['key' => $key, 'config' => $config]);
        }

        return EloquentDestination::fromConfig($key, $config);
    }

    /** What a destination said it did, as a short line for the inbox. */
    private function describe(mixed $result): ?string
    {
        return match (true) {
            $result === null => null,
            is_array($result) && isset($result['model'], $result['id']) => $result['model'].' #'.$result['id'],
            is_scalar($result) => Str::limit((string) $result, 200),
            is_array($result) => Str::limit((string) json_encode($result), 200),
            default => null,
        };
    }
}
