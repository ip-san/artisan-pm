<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{project: Project, user: User, tracker: Tracker}
 */
function cfSortSetup(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));

    return compact('project', 'user', 'tracker');
}

function cfSortIssue(Project $project, Tracker $tracker, string $subject, ?CustomField $field = null, mixed $value = null): Issue
{
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
    ]);

    if ($field !== null && $value !== null) {
        $issue->setCustomFieldValues([$field->id => $value]);
    }

    return $issue;
}

/**
 * @return array<int, string>
 */
function cfSortedSubjects(Project $project, User $user, string $key, string $direction = 'asc'): array
{
    return Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('sortKey', $key)->set('sortDirection', $direction)
        ->get('issues')->pluck('subject')->all();
}

test('a text custom field sorts by value with blanks first ascending and last descending', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $field = CustomField::factory()->create(['is_filter' => false]);
    $field->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'banana', $field, 'banana');
    cfSortIssue($project, $tracker, 'blank');
    cfSortIssue($project, $tracker, 'apple', $field, 'apple');

    expect(cfSortedSubjects($project, $user, "cf_{$field->id}"))->toBe(['blank', 'apple', 'banana'])
        ->and(cfSortedSubjects($project, $user, "cf_{$field->id}", 'desc'))->toBe(['banana', 'apple', 'blank']);
});

test('a numeric custom field sorts as a number, not as text', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Int->value]);
    $field->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'ten', $field, 10);
    cfSortIssue($project, $tracker, 'two', $field, 2);
    cfSortIssue($project, $tracker, 'hundred', $field, 100);

    expect(cfSortedSubjects($project, $user, "cf_{$field->id}"))->toBe(['two', 'ten', 'hundred']);
});

test('a date custom field sorts chronologically', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Date->value]);
    $field->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'late', $field, '2027-01-01');
    cfSortIssue($project, $tracker, 'early', $field, '2026-01-01');

    expect(cfSortedSubjects($project, $user, "cf_{$field->id}"))->toBe(['early', 'late']);
});

test('a custom field can be the second sort level and ties keep the first level order', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'same-b', $field, 'b');
    cfSortIssue($project, $tracker, 'same-a', $field, 'a');

    $ids = Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('sortKey', 'done_ratio')->set('sortKey2', "cf_{$field->id}")
        ->get('issues')->pluck('subject')->all();

    expect($ids)->toBe(['same-a', 'same-b']);
});

test('sorting by a custom field does not duplicate or drop issues', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $a = CustomField::factory()->create();
    $b = CustomField::factory()->create();
    $a->trackers()->attach($tracker);
    $b->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'one', $a, 'x');
    cfSortIssue($project, $tracker, 'two');

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('sortKey', "cf_{$a->id}")->set('sortKey2', "cf_{$b->id}");

    expect($list->get('issues')->total())->toBe(2);
});

test('a multi-value custom field is not offered for sorting and ignored if requested', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $field = CustomField::factory()->multiple()->create();
    $field->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'x');

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);
    expect($list->get('sortableColumns'))->not->toHaveKey("cf_{$field->id}");
    expect($list->set('sortKey', "cf_{$field->id}")->get('issues')->total())->toBe(1);
});

test('a custom field of another project cannot be sorted by', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $foreign = CustomField::factory()->create();
    $foreign->trackers()->attach($tracker);
    $foreign->projects()->attach(Project::factory()->create());
    cfSortIssue($project, $tracker, 'x');

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);

    expect($list->get('sortableColumns'))->not->toHaveKey("cf_{$foreign->id}");
    expect($list->set('sortKey', "cf_{$foreign->id}")->get('issues')->total())->toBe(1);
});

test('clicking a custom field column heading sorts by it', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfSortSetup();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    cfSortIssue($project, $tracker, 'b', $field, 'b');
    cfSortIssue($project, $tracker, 'a', $field, 'a');

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('columns', ['subject', "cf_{$field->id}"])->call('sortBy', "cf_{$field->id}");

    expect($list->get('issues')->pluck('subject')->all())->toBe(['a', 'b']);
});
