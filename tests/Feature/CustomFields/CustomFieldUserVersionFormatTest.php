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

test('the edit form is prefilled with the id, so the saved value survives a save', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member] = recordListSetup();
    $userField = recordListField($tracker, CustomFieldFormat::User);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'author_id' => $editor->id]);
    $issue->setCustomFieldValues([$userField->id => (string) $member->id]);

    $component = Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSet("customFieldValues.{$userField->id}", (string) $member->id)
        ->call('save')->assertHasNoErrors();

    expect($issue->fresh()->customValue($userField))->toBe('Mia Member');
});

test('an issue list filter picks issues by the chosen user, and "me" means the viewer', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User);
    $forMember = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'subject' => 'For member']);
    $forEditor = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'subject' => 'For editor']);
    $forMember->setCustomFieldValues([$field->id => (string) $member->id]);
    $forEditor->setCustomFieldValues([$field->id => (string) $editor->id]);

    $engine = new App\Support\Query\QueryFilterEngine(App\Support\Query\IssueFilterFieldRegistry::forProject($project));
    $subjects = fn (string $value) => $engine->applyFilters(Issue::query(), ["cf_{$field->id}" => ['operator' => '=', 'values' => [$value]]])->pluck('subject')->all();

    expect($subjects((string) $member->id))->toBe(['For member']);

    $this->actingAs($editor);
    expect($subjects('me'))->toBe(['For editor'])
        ->and(array_keys($engine->field("cf_{$field->id}")->options()))->toContain('me');
});

test('the API sends the stored id and no possible_values for a user field', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User, ['name' => 'Reviewer']);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => (string) $member->id]);
    $admin = User::factory()->admin()->create();
    Passport::actingAs($admin);

    $value = collect($this->getJson("/api/v1/issues/{$issue->id}")->assertOk()->json('data.custom_fields'))->firstWhere('id', $field->id);
    expect($value['value'])->toBe((string) $member->id);

    $definition = collect($this->getJson('/api/v1/custom_fields')->assertOk()->json('data'))->firstWhere('id', $field->id);
    expect($definition['possible_values'])->toBe([])->and($definition['field_format'])->toBe('user');
});

test('a change to the value is journaled with the names', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User, ['name' => 'Reviewer']);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'author_id' => $editor->id]);

    app(App\Services\IssueService::class)->update($issue, [], $editor, null, [$field->id => (string) $member->id]);

    $detail = $issue->journals()->with('details')->get()->flatMap->details->firstWhere('property', 'cf');
    expect($detail->new_value)->toBe('Mia Member');
});

test('an enumeration field is prefilled with the option id too', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::Enumeration);
    $option = App\Models\CustomFieldEnumeration::factory()->create(['custom_field_id' => $field->id, 'name' => 'Gold', 'active' => true]);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'author_id' => $editor->id]);
    $issue->setCustomFieldValues([$field->id => (string) $option->id]);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSet("customFieldValues.{$field->id}", (string) $option->id)
        ->call('save')->assertHasNoErrors();
});

test('a multiple user field renders a multi-select and saves every chosen member', function () {
    ['project' => $project, 'tracker' => $tracker, 'editor' => $editor, 'member' => $member] = recordListSetup();
    $field = recordListField($tracker, CustomFieldFormat::User, ['multiple' => true, 'name' => 'Reviewers']);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'author_id' => $editor->id]);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSeeHtml('data-multiple-choice')
        ->set('customFieldValues', [$field->id => [(string) $member->id, (string) $editor->id]])
        ->call('save')->assertHasNoErrors();

    expect($issue->fresh()->customFieldValues()->where('custom_field_id', $field->id)->pluck('value_int')->sort()->values()->all())
        ->toBe(collect([$member->id, $editor->id])->sort()->values()->all());

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('customFieldValues', [$field->id => [(string) User::factory()->create()->id]])
        ->call('save')->assertHasErrors();
});
