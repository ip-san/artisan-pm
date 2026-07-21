<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\WikiRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WikiRedirect>
 */
class WikiRedirectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->unique()->sentence(3),
            'redirects_to' => fake()->unique()->sentence(3),
        ];
    }
}
