<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IssueCategory;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueCategory>
 */
class IssueCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
