<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IssueRelationType;
use App\Models\Issue;
use App\Models\IssueRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueRelation>
 */
class IssueRelationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_from_id' => Issue::factory(),
            'issue_to_id' => Issue::factory(),
            'relation_type' => IssueRelationType::Relates->value,
        ];
    }
}
