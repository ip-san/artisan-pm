<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Repository;
use App\Models\RepositoryCommitter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryCommitter>
 */
class RepositoryCommitterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'committer' => fake()->unique()->name().' <'.fake()->unique()->safeEmail().'>',
            'user_id' => User::factory(),
        ];
    }
}
