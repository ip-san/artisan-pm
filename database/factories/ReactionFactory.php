<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Issue;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reactable_type' => Issue::class,
            'reactable_id' => Issue::factory(),
            'user_id' => User::factory(),
        ];
    }
}
