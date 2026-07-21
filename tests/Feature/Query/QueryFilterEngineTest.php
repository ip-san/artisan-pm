<?php

use App\Enums\CustomFieldFormat;
use App\Enums\FilterOperator;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;

function issueEngine(Project $project): QueryFilterEngine
{
    return new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project));
}

test('filtering issues by status_id with the equals operator narrows the result set', function () {
    $project = Project::factory()->create();
    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();

    $matching = Issue::factory()->for($project)->create(['status_id' => $statusA->id]);
    Issue::factory()->for($project)->create(['status_id' => $statusB->id]);

    $engine = issueEngine($project);

    $results = $engine->applyFilters(Issue::query(), [
        'status_id' => ['operator' => FilterOperator::Equals->value, 'values' => [$statusA->id]],
    ])->get();

    expect($results->pluck('id')->all())->toBe([$matching->id]);
});

test('the subject field supports a contains filter', function () {
    $project = Project::factory()->create();
    $matching = Issue::factory()->for($project)->create(['subject' => 'Login page crashes on submit']);
    Issue::factory()->for($project)->create(['subject' => 'Unrelated issue']);

    $engine = issueEngine($project);

    $results = $engine->applyFilters(Issue::query(), [
        'subject' => ['operator' => FilterOperator::Contains->value, 'values' => ['crashes']],
    ])->get();

    expect($results->pluck('id')->all())->toBe([$matching->id]);
});

test('an unknown field key or operator is silently ignored rather than erroring', function () {
    $project = Project::factory()->create();
    Issue::factory()->for($project)->count(2)->create();

    $engine = issueEngine($project);

    $results = $engine->applyFilters(Issue::query(), [
        'not_a_real_field' => ['operator' => '=', 'values' => [1]],
        'subject' => ['operator' => 'not_a_real_operator', 'values' => ['x']],
    ])->get();

    expect($results)->toHaveCount(2);
});

test('sorting by a native column orders the results', function () {
    $project = Project::factory()->create();
    $late = Issue::factory()->for($project)->create(['subject' => 'B', 'created_at' => now()->addMinute()]);
    $early = Issue::factory()->for($project)->create(['subject' => 'A', 'created_at' => now()]);

    $engine = issueEngine($project);

    $results = $engine->applySort(Issue::query(), [['subject', 'asc']])->get();

    expect($results->pluck('id')->all())->toBe([$early->id, $late->id]);
});

test('custom field filters only match issues whose value satisfies the operator', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Int->value]);
    $field->trackers()->attach($tracker);

    $highSeverity = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $highSeverity->setCustomFieldValues([$field->id => 9]);

    $lowSeverity = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $lowSeverity->setCustomFieldValues([$field->id => 2]);

    $engine = issueEngine($project);
    $key = "cf_{$field->id}";

    $results = $engine->applyFilters(Issue::query(), [
        $key => ['operator' => FilterOperator::GreaterOrEqual->value, 'values' => [5]],
    ])->get();

    expect($results->pluck('id')->all())->toBe([$highSeverity->id]);
});

test('a custom field not attached to the project is not offered as a filterable field', function () {
    $project = Project::factory()->create();
    $otherTracker = Tracker::factory()->create();

    $field = CustomField::factory()->create();
    $field->trackers()->attach($otherTracker);

    $engine = issueEngine($project);

    expect($engine->field("cf_{$field->id}"))->toBeNull();
});

test('a custom field is not sortable', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);

    $engine = issueEngine($project);

    expect($engine->field("cf_{$field->id}")->isSortable())->toBeFalse();
});
