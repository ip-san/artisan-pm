<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'activity_id' => Enumeration::factory()->state(['type' => EnumerationType::TimeEntryActivity->value]),
            'hours' => fake()->randomFloat(2, 0.5, 8),
            'spent_on' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
        ];
    }
}
