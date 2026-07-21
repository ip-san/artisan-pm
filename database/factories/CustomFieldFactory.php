<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'field_format' => CustomFieldFormat::String->value,
            'customized_type' => CustomizableType::Issue->value,
            'is_required' => false,
            'multiple' => false,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes) => ['is_required' => true]);
    }

    public function multiple(): static
    {
        return $this->state(fn (array $attributes) => ['multiple' => true]);
    }

    public function list(array $possibleValues): static
    {
        return $this->state(fn (array $attributes) => [
            'field_format' => CustomFieldFormat::List->value,
            'possible_values' => $possibleValues,
        ]);
    }
}
