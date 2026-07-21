<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\News;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<News>
 */
class NewsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'author_id' => User::factory(),
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(),
            'description' => fake()->paragraphs(3, true),
        ];
    }
}
