<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Tracker;

test('a custom field is relevant to an issue only when its tracker is attached', function () {
    $tracker = Tracker::factory()->create();
    $otherTracker = Tracker::factory()->create();
    $project = Project::factory()->create();

    $field = CustomField::factory()->create(['name' => 'Client email']);
    $field->trackers()->attach($tracker);

    $matching = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $nonMatching = Issue::factory()->for($project)->create(['tracker_id' => $otherTracker->id]);

    expect($matching->relevantCustomFields()->pluck('id'))->toContain($field->id)
        ->and($nonMatching->relevantCustomFields()->pluck('id'))->not->toContain($field->id);
});

test('a custom field scoped to specific projects only applies there', function () {
    $tracker = Tracker::factory()->create();
    $includedProject = Project::factory()->create();
    $excludedProject = Project::factory()->create();

    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    $field->projects()->attach($includedProject);

    $includedIssue = Issue::factory()->for($includedProject)->create(['tracker_id' => $tracker->id]);
    $excludedIssue = Issue::factory()->for($excludedProject)->create(['tracker_id' => $tracker->id]);

    expect($includedIssue->relevantCustomFields()->pluck('id'))->toContain($field->id)
        ->and($excludedIssue->relevantCustomFields()->pluck('id'))->not->toContain($field->id);
});

test('setting and reading a string custom field value round-trips correctly', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::String->value]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $issue->setCustomFieldValues([$field->id => 'client@example.com']);

    expect($issue->customValue($field))->toBe('client@example.com');
});

test('a multiple-value custom field stores one row per value', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->multiple()->create(['field_format' => CustomFieldFormat::String->value]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $issue->setCustomFieldValues([$field->id => ['red', 'green', 'blue']]);

    expect($issue->customFieldValues()->where('custom_field_id', $field->id)->count())->toBe(3);
});

test('an int custom field casts its stored value back to an integer', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Int->value]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => '42']);

    expect($issue->customValue($field))->toBe(42);
});

test('a list custom field only accepts one of its possible values', function () {
    $field = CustomField::factory()->list(['Low', 'Medium', 'High'])->create();

    expect($field->format()->validationRules($field))->toContain('string');
});

test('setting a value for a field the issue does not apply to is ignored', function () {
    $tracker = Tracker::factory()->create();
    $unrelatedTracker = Tracker::factory()->create();
    $project = Project::factory()->create();

    $field = CustomField::factory()->create();
    $field->trackers()->attach($unrelatedTracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 'ignored']);

    expect($issue->customFieldValues()->count())->toBe(0);
});
