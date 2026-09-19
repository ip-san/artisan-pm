<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{project: Project, user: User, activity: Enumeration, role: Role}
 */
function timeEntryCfSetup(array $permissions = ['view_time_entries', 'log_time', 'edit_time_entries']): array
{
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);

    return compact('project', 'user', 'activity', 'role');
}

function timeEntryCf(array $attributes = []): CustomField
{
    return CustomField::factory()->create(['customized_type' => 'time_entry', ...$attributes]);
}

test('a time entry custom field appears on the form and its value is saved', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $field = timeEntryCf(['name' => 'Ticket ref']);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->assertSee('Ticket ref')
        ->set('activity_id', $activity->id)->set('hours', '1.5')->set('customFieldValues', [$field->id => 'ABC-1'])
        ->call('save')->assertHasNoErrors();

    $entry = TimeEntry::query()->latest('id')->firstOrFail();
    expect($entry->customValue($field))->toBe('ABC-1');
});

test('editing an entry loads and updates its custom field value', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $field = timeEntryCf();
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id, 'activity_id' => $activity->id]);
    $entry->setCustomFieldValues([$field->id => 'before']);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project, 'timeEntry' => $entry])
        ->assertSet("customFieldValues.{$field->id}", 'before')
        ->set("customFieldValues.{$field->id}", 'after')->call('save')->assertHasNoErrors();

    expect($entry->fresh()->customValue($field))->toBe('after');
});

test('a required field must be filled and a format is validated', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $required = timeEntryCf(['is_required' => true]);
    $number = timeEntryCf(['field_format' => CustomFieldFormat::Int->value]);

    $form = Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])->set('activity_id', $activity->id)->set('hours', '1');
    $form->call('save')->assertHasErrors(["customFieldValues.{$required->id}"]);

    $form->set('customFieldValues', [$required->id => 'x', $number->id => 'not a number'])->call('save')->assertHasErrors(["customFieldValues.{$number->id}"]);
});

test('a field restricted to other roles is hidden and its value ignored', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $hidden = timeEntryCf(['name' => 'Managers only']);
    $hidden->roles()->attach(Role::factory()->create());

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->assertDontSee('Managers only')
        ->set('activity_id', $activity->id)->set('hours', '1')->set('customFieldValues', [$hidden->id => 'sneaky'])->call('save');

    expect(TimeEntry::query()->latest('id')->firstOrFail()->customValue($hidden))->toBeNull();
});

test('a field limited to other projects is not offered here', function () {
    ['project' => $project, 'user' => $user] = timeEntryCfSetup();
    $field = timeEntryCf(['name' => 'Elsewhere']);
    $field->projects()->attach(Project::factory()->create());

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])->assertDontSee('Elsewhere');
});

test('a non-editable field is shown read-only and cannot be changed by a member', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $field = timeEntryCf(['editable' => false]);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->set('activity_id', $activity->id)->set('hours', '1')->set('customFieldValues', [$field->id => 'forced'])->call('save');

    expect(TimeEntry::query()->latest('id')->firstOrFail()->customValue($field))->toBeNull();
});

test('the list offers custom fields as columns and shows their values', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $field = timeEntryCf(['name' => 'Ticket ref']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id, 'activity_id' => $activity->id]);
    $entry->setCustomFieldValues([$field->id => 'REF-42']);

    $list = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project]);
    expect($list->get('availableColumns'))->toHaveKey("cf_{$field->id}");

    $list->set('columns', ['spent_on', "cf_{$field->id}"])->assertSee('Ticket ref')->assertSee('REF-42');
});

test('the CSV export includes the custom field column', function () {
    ['project' => $project, 'user' => $user, 'activity' => $activity] = timeEntryCfSetup();
    $field = timeEntryCf(['name' => 'Ticket ref']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id, 'activity_id' => $activity->id, 'hours' => 1]);
    $entry->setCustomFieldValues([$field->id => 'REF-42']);

    $component = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])
        ->set('columns', ["cf_{$field->id}"])->call('exportCsv');

    expect(base64_decode($component->effects['download']['content']))->toContain('Ticket ref')->toContain('REF-42');
});

test('an issue priority can carry custom fields on its admin form', function () {
    $admin = User::factory()->admin()->create();
    $priority = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);
    $field = CustomField::factory()->create(['customized_type' => 'issue_priority', 'name' => 'SLA hours']);

    Livewire::actingAs($admin)->test('enumerations.form', ['type' => EnumerationType::IssuePriority, 'enumeration' => $priority])->assertSee('SLA hours')
        ->set("customFieldValues.{$field->id}", '4')->call('save')->assertHasNoErrors();

    expect($priority->fresh()->customValue($field))->toBe('4');
});

test('the admin can create a field for time entries and priorities', function () {
    $admin = User::factory()->admin()->create();

    foreach (['time_entry', 'issue_priority'] as $type) {
        Livewire::actingAs($admin)->test('custom-fields.form')->set('customized_type', $type)->set('name', "Field for {$type}")->set('field_format', 'string')->call('save')->assertHasNoErrors();
        expect(CustomField::where('name', "Field for {$type}")->value('customized_type')->value)->toBe($type);
    }
});
