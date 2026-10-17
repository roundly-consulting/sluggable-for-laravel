<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

/** @extends Factory<SlugHistory> */
final class SlugHistoryFactory extends Factory
{
    protected $model = SlugHistory::class;

    /**
     * Build the model the host configured in `sluggable.history.model`, not the packaged one, so a
     * host subclass comes out of the factory with its own events, observers and table.
     *
     * @return class-string<SlugHistory>
     */
    public function modelName(): string
    {
        return SluggableConfig::historyModel();
    }

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
