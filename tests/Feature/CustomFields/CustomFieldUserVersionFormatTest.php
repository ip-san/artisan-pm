<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Enums\VersionSharing;
use App\Enums\VersionStatus;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @return array{project: Project, tracker: Tracker, editor: User, member: User, outsider: User, role: Role}
 */
function recordListSetup(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true, 'type' => EnumerationType::IssuePriority->value]);

    $editor = User::factory()->create();
    Member::factory()->for($project)->for($editor)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'edit_issues']]));

    $role = Role::factory()->create(['name' => 'Developer', 'permissions' => ['view_issues']]);
    $member = User::factory()->create(['name' => 'Mia Member']);
    Member::factory()->for($project)->for($member)->create()->roles()->attach($role);

    return ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member, 'outsider' => User::factory()->create(['name' => 'Olga Outsider']), 'role' => $role];
}

function recordListField(Tracker $tracker, CustomFieldFormat $format, array $attributes = []): CustomField
{
    $field = CustomField::factory()->create(['field_format' => $format->value, ...$attributes]);
    $field->trackers()->attach($tracker);

    return $field;
}

test('a user field offers the project\'s members and stores the id', function () {
    ['project' => $project, 'tracker' => $tracker, 'member' => $member, 'outsider' => $outsider] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User);

    expect(array_values($field->optionsFor($project)))->toContain('Mia Member')->not->toContain('Olga Outsider')
        ->and(array_values($field->optionsFor(null)))->toContain('Mia Member')->toContain('Olga Outsider');

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => (string) $member->id]);

    expect($issue->fresh()->customValue($field))->toBe('Mia Member')
        ->and($issue->customFieldValues()->where('custom_field_id', $field->id)->value('value_int'))->toBe($member->id);

    $outsider->update(['status' => App\Enums\UserStatus::Locked]);
    expect(array_values($field->optionsFor(null)))->not->toContain('Olga Outsider');
});

test('a user field limited to roles offers only members holding one of them', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'role' => $role] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User, ['format_options' => ['user_role' => [$role->id]]]);

    expect(array_values($field->optionsFor($project)))->toBe(['Mia Member'])
        ->and(array_values($field->optionsFor($project)))->not->toContain($editor->displayName());
});

test('a version field offers the versions the project can use, filtered by status', function () {
    ['project' => $project, 'tracker' => $tracker] = recordListSetup();
    $open = Version::factory()->for($project)->create(['name' => 'v1', 'status' => VersionStatus::Open]);
    $closed = Version::factory()->for($project)->create(['name' => 'v0', 'status' => VersionStatus::Closed]);
    $foreign = Version::factory()->for(Project::factory()->create())->create(['name' => 'elsewhere', 'sharing' => VersionSharing::None]);

    $any = recordListField($tracker, CustomFieldFormat::Version);
    $openOnly = recordListField($tracker, CustomFieldFormat::Version, ['format_options' => ['version_status' => ['open']]]);

    expect(array_values($any->optionsFor($project)))->toContain('v1')->toContain('v0')->not->toContain('elsewhere')
        ->and(array_values($openOnly->optionsFor($project)))->toBe(['v1'])
        ->and(array_values($any->optionsFor(null)))->toContain('elsewhere');

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$any->id => (string) $closed->id]);
    expect($issue->fresh()->customValue($any))->toBe('v0')->and($open->id)->not->toBe($closed->id);
});

test('the issue form rejects a user or version outside the project', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member, 'outsider' => $outsider] = recordListSetup();
    $userField = recordListField($tracker, CustomFieldFormat::User);
    $versionField = recordListField($tracker, CustomFieldFormat::Version);
    $foreign = Version::factory()->for(Project::factory()->create())->create(['sharing' => VersionSharing::None]);
    $own = Version::factory()->for($project)->create();
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'author_id' => $editor->id]);

    $component = Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('customFieldValues', [$userField->id => (string) $outsider->id, $versionField->id => (string) $foreign->id])
        ->call('save');
    $component->assertHasErrors(["customFieldValues.{$userField->id}", "customFieldValues.{$versionField->id}"]);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('customFieldValues', [$userField->id => (string) $member->id, $versionField->id => (string) $own->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->customValue($userField))->toBe('Mia Member')->and($issue->fresh()->customValue($versionField))->toBe($own->name);
});

test('the API rejects a user outside the project and accepts a member', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member, 'outsider' => $outsider] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User, ['name' => 'Reviewer']);
    $body = ['tracker_id' => $tracker->id, 'priority_id' => Enumeration::query()->value('id'), 'subject' => 'Reviewed'];

    Passport::actingAs($editor);

    $this->postJson("/api/v1/projects/{$project->id}/issues", [...$body, 'custom_fields' => [['id' => $field->id, 'value' => (string) $outsider->id]]])->assertUnprocessable();
    $this->postJson("/api/v1/projects/{$project->id}/issues", [...$body, 'custom_fields' => [['id' => $field->id, 'value' => (string) $member->id]]])->assertCreated();

    expect(Issue::query()->where('subject', 'Reviewed')->sole()->customValue($field))->toBe('Mia Member');
});

test('the bulk edit does not offer project-dependent fields', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor] = recordListSetup();
    $userField = recordListField($tracker, CustomFieldFormat::User);
    $textField = recordListField($tracker, CustomFieldFormat::String);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'author_id' => $editor->id]);

    $ids = Livewire::actingAs($editor)->test('issues.index', ['project' => $project])
        ->set('selected', [(string) $issue->id])
        ->get('bulkCustomFields')->pluck('id');

    expect($ids)->toContain($textField->id)->not->toContain($userField->id);
});

test('the admin form saves the role and status options', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('name', 'Owner')->set('customized_type', 'issue')->set('field_format', 'user')
        ->set('trackerIds', [Tracker::factory()->create()->id])
        ->set('userRoleIds', [(string) $role->id])
        ->call('save')->assertHasNoErrors();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('name', 'Target')->set('customized_type', 'issue')->set('field_format', 'version')
        ->set('trackerIds', [Tracker::factory()->create()->id])
        ->set('versionStatuses', ['open', 'locked'])
        ->call('save')->assertHasNoErrors();

    expect(CustomField::query()->where('name', 'Owner')->sole()->format_options)->toBe(['user_role' => [$role->id]])
        ->and(CustomField::query()->where('name', 'Target')->sole()->format_options)->toBe(['version_status' => ['open', 'locked']]);
});
