<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Changeset;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Changeset>
 */
class ChangesetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'revision' => fake()->unique()->sha1(),
            'committer' => fake()->name().' <'.fake()->safeEmail().'>',
            'committed_on' => fake()->dateTimeBetween('-1 month', 'now'),
            'comments' => fake()->sentence(),
        ];
    }
}
