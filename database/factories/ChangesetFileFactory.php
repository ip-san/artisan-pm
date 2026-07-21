<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Changeset;
use App\Models\ChangesetFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangesetFile>
 */
class ChangesetFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'changeset_id' => Changeset::factory(),
            'path' => fake()->filePath(),
            'action' => fake()->randomElement(['A', 'M', 'D']),
        ];
    }
}
