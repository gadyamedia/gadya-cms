<?php

namespace Gadya\Cms\Database\Factories;

use Gadya\Cms\Models\Post;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'site_id' => fn (): ?int => app(SiteContext::class)->id(),
            'title' => rtrim($title, '.'),
            'slug' => fake()->unique()->slug(3),
            'excerpt' => fake()->sentence(12),
            'content' => '<h2>'.fake()->sentence(3).'</h2><p>'.fake()->paragraph(4).'</p>',
            'status' => Post::STATUS_DRAFT,
            'source' => Post::SOURCE_MANUAL,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduled(): self
    {
        return $this->state(fn (): array => [
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->addWeek(),
        ]);
    }
}
