<?php

namespace Database\Factories;

use App\Models\Tracker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tracker>
 */
class TrackerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
        ];
    }
}
