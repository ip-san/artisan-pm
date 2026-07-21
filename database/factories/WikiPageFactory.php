<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WikiPage>
 */
class WikiPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->unique()->sentence(3),
            'is_protected' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (WikiPage $page) {
            $page->versions()->create([
                'author_id' => User::factory()->create()->id,
                'text' => fake()->paragraphs(3, true),
                'version' => 1,
            ]);
        });
    }
}
