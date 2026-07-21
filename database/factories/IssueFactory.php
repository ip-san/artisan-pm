<?php

namespace Database\Factories;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'tracker_id' => Tracker::factory(),
            'status_id' => IssueStatus::factory(),
            'priority_id' => Enumeration::factory()->state(['type' => EnumerationType::IssuePriority->value]),
            'author_id' => User::factory(),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
        ];
    }
}
