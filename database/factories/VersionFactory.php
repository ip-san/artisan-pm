<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Version>
 */
class VersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
        ];
    }
}
