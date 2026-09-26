<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Sluggable\Models\SlugHistory;

/** @extends Factory<SlugHistory> */
final class SlugHistoryFactory extends Factory
{
    protected $model = SlugHistory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sluggable_type' => 'App\\Models\\Page',
            'sluggable_id' => $this->faker->numberBetween(1, 100000),
            'column' => 'slug',
            'locale' => '',
            'slug' => $this->faker->unique()->slug(3),
        ];
    }
}
