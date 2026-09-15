<?php

namespace Gadya\Cms\Database\Factories;

use Gadya\Cms\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'key' => fake()->unique()->slug(2),
            'is_active' => true,
        ];
    }
}
