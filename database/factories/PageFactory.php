<?php

namespace Gadya\Cms\Database\Factories;

use Gadya\Cms\Models\Page;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'site_id' => fn (): ?int => app(SiteContext::class)->id(),
            'slug' => fake()->unique()->slug(2),
            'title' => $title,
            'type' => 'content',
            'status' => Page::STATUS_PUBLISHED,
            'draft' => [
                'heading' => $title,
                'description' => fake()->sentence(),
                'sections' => [],
            ],
        ];
    }
}
