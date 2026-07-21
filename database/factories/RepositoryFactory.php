<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RepositoryType;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => RepositoryType::Git->value,
            'path' => sys_get_temp_dir().'/'.fake()->unique()->uuid(),
        ];
    }
}
