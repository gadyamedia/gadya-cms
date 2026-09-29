<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Ai\Agents\FormWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Ai\Prompter;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Describe your form": a draft form written by the site's AI, through
 * whichever route the site already writes with - the client's own key
 * when she has one, Gadya Media's through the portal otherwise. The
 * answer is checked like anything else the builder saves: fields of a
 * kind the site does not know are dropped, and the form is a draft.
 */
class FormGenerator
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly PortalBrain $brain,
        private readonly Prompter $prompter,
        private readonly FieldTypes $types,
        private readonly FormSchemaValidator $validator,
    ) {}

    public function available(): bool
    {
        return $this->settings->isConfigured() || $this->brain->available();
    }

    public function generate(string $description): Form
    {
        $answer = $this->ask(trim($description));
        $fields = $this->clean($answer['fields'] ?? []);

        if ($fields === [] || $this->validator->errors($fields) !== []) {
            throw new RuntimeException('The AI did not write a form the site could use. Try describing it again, perhaps listing the questions you want.');
        }

        $title = Str::limit(trim((string) ($answer['title'] ?? '')) ?: 'New form', 80, '');

        return Form::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'title' => $title,
            'slug' => app(FormTemplates::class)->availableSlug($title),
            'status' => Form::STATUS_DRAFT,
            'template' => 'ai',
            'fields' => $fields,
            'messages' => array_filter(['success' => Str::limit(trim((string) ($answer['success'] ?? '')), 300, '')]),
            'settings' => [],
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $description): array
    {
        $agent = app(FormWriter::class);
        $prompt = "The form they want:\n".$description;

        if ($this->settings->isConfigured()) {
            $response = $this->prompter->prompt($agent, $prompt);

            return ['title' => $response['title'] ?? '', 'fields' => $response['fields'] ?? [], 'success' => $response['success'] ?? ''];
        }

        if (! $this->brain->available()) {
            throw new RuntimeException('This site has no AI to write with. Add a key under Settings → AI, or connect the site to Gadya Media.');
        }

        $text = $this->brain->write(
            $prompt,
            $agent->instructions()."\n\nAnswer with JSON only, no other words: {\"title\": \"...\", \"fields\": [{\"type\": \"...\", \"label\": \"...\", \"required\": true, \"help\": \"...\", \"options\": [\"...\"]}], \"success\": \"...\"}",
            words: 900,
        );

        $json = $text === null ? null : json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)), true);

        if (! is_array($json)) {
            throw new RuntimeException('The AI did not answer in a way the site could read. Try again in a minute.');
        }

        return $json;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clean(mixed $fields): array
    {
        $clean = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_array($field) || ! is_string($field['type'] ?? null) || ! $this->types->has($field['type'])) {
                continue;
            }

            $type = $this->types->get($field['type']);
            $options = array_values(array_filter(array_map(fn ($option): string => trim((string) (is_array($option) ? ($option['label'] ?? '') : $option)), (array) ($field['options'] ?? []))));

            if ($type->hasChoices() && $options === []) {
                continue;
            }

            $clean[] = [
                'type' => $field['type'],
                'label' => Str::limit(trim((string) ($field['label'] ?? '')), 200, ''),
                'help' => Str::limit(trim((string) ($field['help'] ?? '')), 300, ''),
                'required' => (bool) ($field['required'] ?? false),
                'options' => $type->hasChoices() ? array_map(fn (string $label): array => ['label' => Str::limit($label, 120, '')], $options) : [],
            ];
        }

        return FormSchema::normalise($clean);
    }
}
