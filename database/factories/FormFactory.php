<?php

namespace Gadya\Cms\Database\Factories;

use Gadya\Cms\Models\Form;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => fn (): ?int => app(SiteContext::class)->id(),
            'title' => 'Get in touch',
            'slug' => fake()->unique()->slug(2),
            'status' => Form::STATUS_DRAFT,
            'fields' => [
                ['type' => 'name', 'key' => 'name', 'label' => 'Your name', 'required' => true],
                ['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true],
                ['type' => 'long_text', 'key' => 'message', 'label' => 'How can we help?', 'required' => true],
            ],
            'messages' => [],
            'settings' => [],
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => Form::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function withFields(array $fields): self
    {
        return $this->state(fn (): array => ['fields' => $fields]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function withSettings(array $settings): self
    {
        return $this->state(fn (): array => ['settings' => $settings]);
    }
}
