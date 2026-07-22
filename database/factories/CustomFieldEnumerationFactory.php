<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldEnumeration>
 */
class CustomFieldEnumerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory(),
            'name' => fake()->unique()->word(),
            'active' => true,
        ];
    }
}
