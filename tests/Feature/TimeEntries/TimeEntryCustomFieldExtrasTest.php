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
 * @return array{project: Project, user: User}
 */
function timeCfExtrasSetup(): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries', 'log_time', 'edit_time_entries']]));

    return compact('project', 'user');
}

function timeCfExtrasEntry(Project $project, User $user): TimeEntry
{
    return TimeEntry::factory()->for($project)->create([
        'user_id' => $user->id, 'hours' => 1,
        'activity_id' => Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value])->id,
    ]);
}

test('bulk edit sets a custom field on every selected entry and leaves blank ones alone', function () {
    ['project' => $project, 'user' => $user] = timeCfExtrasSetup();
    $set = CustomField::factory()->list(['Billable', 'Internal'])->create(['customized_type' => 'time_entry', 'name' => 'Billing']);
    $keep = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Ref']);
    $a = timeCfExtrasEntry($project, $user);
    $b = timeCfExtrasEntry($project, $user);
    $a->setCustomFieldValues([$keep->id => 'A-ref']);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->assertSee('data-bulk-custom-fields', false)
        ->set("bulkCustomFieldValues.{$set->id}", 'Billable')
        ->set("bulkCustomFieldValues.{$keep->id}", '')
        ->call('applyBulkEdit')->assertHasNoErrors();

    expect($a->fresh()->customValue($set))->toBe('Billable')->and($b->fresh()->customValue($set))->toBe('Billable')
        ->and($a->fresh()->customValue($keep))->toBe('A-ref');
});

test('a bulk value that fails the field format is rejected and nothing changes', function () {
    ['project' => $project, 'user' => $user] = timeCfExtrasSetup();
    $number = CustomField::factory()->create(['customized_type' => 'time_entry', 'field_format' => CustomFieldFormat::Int->value]);
    $entry = timeCfExtrasEntry($project, $user);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])
        ->set('selected', [(string) $entry->id])->set("bulkCustomFieldValues.{$number->id}", 'abc')->call('applyBulkEdit')->assertHasErrors(["bulkCustomFieldValues.{$number->id}"]);

    expect($entry->fresh()->customValue($number))->toBeNull();
});

test('read-only, multi-value and hidden fields are not offered in the bulk edit', function () {
    ['project' => $project, 'user' => $user] = timeCfExtrasSetup();
    $locked = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Locked', 'editable' => false]);
    $many = CustomField::factory()->list(['x', 'y'])->multiple()->create(['customized_type' => 'time_entry', 'name' => 'Many']);
    $hidden = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Hidden']);
    $hidden->roles()->attach(Role::factory()->create());
    $entry = timeCfExtrasEntry($project, $user);

    $page = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->set('selected', [(string) $entry->id]);

    expect($page->get('bulkCustomFields')->pluck('id')->all())->not->toContain($locked->id, $many->id, $hidden->id);

    $page->set("bulkCustomFieldValues.{$locked->id}", 'forced')->call('applyBulkEdit');
    expect($entry->fresh()->customValue($locked))->toBeNull();
});

test('the cross-project list offers unrestricted custom fields as columns and shows their values', function () {
    ['project' => $project, 'user' => $user] = timeCfExtrasSetup();
    $open = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Ticket']);
    $restricted = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Restricted']);
    $restricted->roles()->attach(Role::factory()->create());
    $entry = timeCfExtrasEntry($project, $user);
    $entry->setCustomFieldValues([$open->id => 'REF-9']);

    $list = Livewire::actingAs($user)->test('time-entries.global-index');
    $columns = $list->get('availableColumns');

    expect($columns)->toHaveKey("cf_{$open->id}")->not->toHaveKey("cf_{$restricted->id}");
    $list->set('columns', ['spent_on', "cf_{$open->id}"])->assertSee('Ticket')->assertSee('REF-9');
});

test('an admin sees restricted custom fields as columns on the cross-project list', function () {
    $admin = User::factory()->admin()->create();
    $restricted = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Restricted']);
    $restricted->roles()->attach(Role::factory()->create());

    expect(Livewire::actingAs($admin)->test('time-entries.global-index')->get('availableColumns'))->toHaveKey("cf_{$restricted->id}");
});
