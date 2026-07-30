<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Wiki;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wiki>
 */
class WikiFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'start_page' => 'Wiki',
        ];
    }
}
