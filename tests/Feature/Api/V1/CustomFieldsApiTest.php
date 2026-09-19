<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Laravel\Passport\Passport;

/**
 * @param  array<int, string>  $permissions
 * @return array{project: Project, user: User, tracker: Tracker}
 */
function cfApiSetup(array $permissions = ['view_issues', 'add_issues', 'edit_issues']): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);

    return compact('project', 'user', 'tracker');
}

function cfApiIssueField(Tracker $tracker, array $attributes = []): CustomField
{
    $field = CustomField::factory()->create($attributes);
    $field->trackers()->attach($tracker);

    return $field;
}

function cfApiById(array $customFields, int $id): ?array
{
    return collect($customFields)->firstWhere('id', $id);
}

test('an issue lists its visible custom fields with their values', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $text = cfApiIssueField($tracker, ['name' => 'Ref']);
    $hidden = cfApiIssueField($tracker, ['name' => 'Managers']);
    $hidden->roles()->attach(Role::factory()->create());
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);
    $issue->setCustomFieldValues([$text->id => 'ABC', $hidden->id => 'secret']);

    Passport::actingAs($user);
    $fields = $this->getJson("/api/v1/issues/{$issue->id}")->assertOk()->json('data.custom_fields');

    expect(cfApiById($fields, $text->id))->toBe(['id' => $text->id, 'name' => 'Ref', 'value' => 'ABC'])
        ->and(cfApiById($fields, $hidden->id))->toBeNull();
});

test('creating an issue with custom_fields stores them, and a missing required one is a 422', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $field = cfApiIssueField($tracker, ['name' => 'Ref']);
    $required = cfApiIssueField($tracker, ['name' => 'Must', 'is_required' => true]);
    $body = ['tracker_id' => $tracker->id, 'priority_id' => Enumeration::query()->value('id'), 'subject' => 'With CFs'];

    Passport::actingAs($user);
    $this->postJson("/api/v1/projects/{$project->id}/issues", [...$body, 'custom_fields' => [['id' => $field->id, 'value' => 'ABC']]])->assertUnprocessable();

    $response = $this->postJson("/api/v1/projects/{$project->id}/issues", [...$body, 'custom_fields' => [['id' => $field->id, 'value' => 'ABC'], ['id' => $required->id, 'value' => 'yes']]])->assertCreated();

    expect(cfApiById($response->json('data.custom_fields'), $field->id)['value'])->toBe('ABC')
        ->and(Issue::find($response->json('data.id'))->customValue($required))->toBe('yes');
});

test('updating an issue changes only the fields sent and ignores unknown or read-only ones', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $keep = cfApiIssueField($tracker, ['name' => 'Keep']);
    $change = cfApiIssueField($tracker, ['name' => 'Change']);
    $readOnly = cfApiIssueField($tracker, ['name' => 'Locked', 'editable' => false]);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);
    $issue->setCustomFieldValues([$keep->id => 'kept', $change->id => 'old', $readOnly->id => 'fixed']);

    Passport::actingAs($user);
    $this->putJson("/api/v1/issues/{$issue->id}", ['custom_fields' => [['id' => $change->id, 'value' => 'new'], ['id' => $readOnly->id, 'value' => 'hacked'], ['id' => 99999, 'value' => 'x']]])->assertOk();

    $fresh = $issue->fresh();
    expect($fresh->customValue($keep))->toBe('kept')->and($fresh->customValue($change))->toBe('new')->and($fresh->customValue($readOnly))->toBe('fixed');
});

test('a value that fails the field format is rejected and nothing is changed', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $number = cfApiIssueField($tracker, ['field_format' => CustomFieldFormat::Int->value]);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id, 'subject' => 'Same']);

    Passport::actingAs($user);
    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Changed', 'custom_fields' => [['id' => $number->id, 'value' => 'abc']]])->assertUnprocessable();

    expect($issue->fresh()->subject)->toBe('Same');
});

test('a multi-value field reads and writes as an array', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $field = cfApiIssueField($tracker, ['name' => 'Tags', 'multiple' => true, 'field_format' => CustomFieldFormat::List->value, 'possible_values' => ['a', 'b', 'c']]);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);

    Passport::actingAs($user);
    $this->putJson("/api/v1/issues/{$issue->id}", ['custom_fields' => [['id' => $field->id, 'value' => ['a', 'c']]]])->assertOk();

    $entry = cfApiById($this->getJson("/api/v1/issues/{$issue->id}")->json('data.custom_fields'), $field->id);
    expect($entry['multiple'])->toBeTrue()->and($entry['value'])->toBe(['a', 'c']);
});

test('an enumeration field round-trips its option id and a boolean reads as 1 or 0', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $enum = cfApiIssueField($tracker, ['name' => 'Team', 'field_format' => CustomFieldFormat::Enumeration->value]);
    $option = CustomFieldEnumeration::create(['custom_field_id' => $enum->id, 'name' => 'Red', 'active' => true, 'position' => 1]);
    $bool = cfApiIssueField($tracker, ['name' => 'Flag', 'field_format' => CustomFieldFormat::Bool->value]);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);

    Passport::actingAs($user);
    $this->putJson("/api/v1/issues/{$issue->id}", ['custom_fields' => [['id' => $enum->id, 'value' => (string) $option->id], ['id' => $bool->id, 'value' => '1']]])->assertOk();

    $fields = $this->getJson("/api/v1/issues/{$issue->id}")->json('data.custom_fields');
    expect(cfApiById($fields, $enum->id)['value'])->toBe((string) $option->id)->and(cfApiById($fields, $bool->id)['value'])->toBe('1');
});

test('time entries carry custom fields through create, read and update', function () {
    ['project' => $project, 'user' => $user] = cfApiSetup(['view_time_entries', 'log_time', 'edit_time_entries']);
    $field = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Ticket']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);

    Passport::actingAs($user);
    $created = $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['hours' => 1.5, 'spent_on' => '2026-03-10', 'activity_id' => $activity->id, 'custom_fields' => [['id' => $field->id, 'value' => 'T-1']]])->assertCreated();
    expect(cfApiById($created->json('data.custom_fields'), $field->id)['value'])->toBe('T-1');

    $id = $created->json('data.id');
    $this->putJson("/api/v1/time_entries/{$id}", ['custom_fields' => [['id' => $field->id, 'value' => 'T-2']]])->assertOk();
    expect(TimeEntry::find($id)->customValue($field))->toBe('T-2');
});

test('projects, versions and groups carry custom fields', function () {
    $admin = User::factory()->admin()->create();
    $projectField = CustomField::factory()->create(['customized_type' => 'project', 'name' => 'Client']);
    $versionField = CustomField::factory()->create(['customized_type' => 'version', 'name' => 'Codename']);
    $groupField = CustomField::factory()->create(['customized_type' => 'group', 'name' => 'Cost center']);
    $tracker = Tracker::factory()->create();
    Passport::actingAs($admin);

    $project = $this->postJson('/api/v1/projects', ['name' => 'CF Project', 'identifier' => 'cf-proj', 'tracker_ids' => [$tracker->id], 'custom_fields' => [['id' => $projectField->id, 'value' => 'ACME']]])->assertCreated();
    expect(cfApiById($project->json('data.custom_fields'), $projectField->id)['value'])->toBe('ACME');

    $version = $this->postJson("/api/v1/projects/{$project->json('data.id')}/versions", ['name' => 'v1', 'custom_fields' => [['id' => $versionField->id, 'value' => 'Falcon']]])->assertCreated();
    expect(cfApiById($version->json('data.custom_fields'), $versionField->id)['value'])->toBe('Falcon');

    $group = $this->postJson('/api/v1/groups', ['name' => 'Ops', 'custom_fields' => [['id' => $groupField->id, 'value' => 'CC-9']]])->assertCreated();
    expect(cfApiById($group->json('data.custom_fields'), $groupField->id)['value'])->toBe('CC-9');

    $this->putJson("/api/v1/groups/{$group->json('data.id')}", ['custom_fields' => [['id' => $groupField->id, 'value' => 'CC-10']]])->assertOk();
    expect(cfApiById($this->getJson("/api/v1/groups/{$group->json('data.id')}")->json('data.custom_fields'), $groupField->id)['value'])->toBe('CC-10');
});

test('user custom fields are shown to admins and the user themselves only', function () {
    $field = CustomField::factory()->create(['customized_type' => 'user', 'name' => 'Employee no']);
    $subject = User::factory()->create();
    $subject->setCustomFieldValues([$field->id => 'E-77']);
    $project = Project::factory()->create();
    $peer = User::factory()->create();
    Member::factory()->for($project)->for($subject)->create();
    Member::factory()->for($project)->for($peer)->create();

    Passport::actingAs($peer);
    expect($this->getJson("/api/v1/users/{$subject->id}")->assertOk()->json('data'))->not->toHaveKey('custom_fields');

    Passport::actingAs($subject);
    expect(cfApiById($this->getJson("/api/v1/users/{$subject->id}")->json('data.custom_fields'), $field->id)['value'])->toBe('E-77');

    Passport::actingAs(User::factory()->admin()->create());
    $created = $this->postJson('/api/v1/users', ['login' => 'cfuser', 'name' => 'CF', 'email' => 'cf@example.com', 'password' => 'a-strong-password-1', 'custom_fields' => [['id' => $field->id, 'value' => 'E-1']]])->assertCreated();
    expect(cfApiById($created->json('data.custom_fields'), $field->id)['value'])->toBe('E-1');
});

test('a request without custom_fields leaves values alone on update', function () {
    ['project' => $project, 'user' => $user, 'tracker' => $tracker] = cfApiSetup();
    $field = cfApiIssueField($tracker);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);
    $issue->setCustomFieldValues([$field->id => 'stay']);

    Passport::actingAs($user);
    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Only the subject'])->assertOk();

    expect($issue->fresh()->customValue($field))->toBe('stay');
});
