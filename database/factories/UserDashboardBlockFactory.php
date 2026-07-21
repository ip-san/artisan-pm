<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDashboardBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDashboardBlock>
 */
class UserDashboardBlockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'block_key' => 'assigned_issues',
            'position' => 0,
        ];
    }
}
