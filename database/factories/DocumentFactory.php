<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'category_id' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
        ];
    }
}
