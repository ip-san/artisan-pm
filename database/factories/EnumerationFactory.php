<?php

namespace Database\Factories;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enumeration>
 */
class EnumerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => EnumerationType::IssuePriority->value,
            'name' => fake()->unique()->word(),
            'is_default' => false,
            'active' => true,
        ];
    }
}
