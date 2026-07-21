<?php

namespace Database\Factories;

use App\Models\IssueStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueStatus>
 */
class IssueStatusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'is_closed' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_closed' => true,
        ]);
    }
}
