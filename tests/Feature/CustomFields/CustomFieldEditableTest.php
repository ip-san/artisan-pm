<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('a non-editable custom field is not editable by a regular user', function () {
    $field = CustomField::factory()->create(['editable' => false]);
    $user = User::factory()->create();

    expect($field->editableBy($user))->toBeFalse();
});

test('a non-editable custom field is still editable by an admin', function () {
    $field = CustomField::factory()->create(['editable' => false]);
    $admin = User::factory()->admin()->create();

    expect($field->editableBy($admin))->toBeTrue();
});

test('editable is true by default and applies to anyone', function () {
    $field = CustomField::factory()->create();
    $user = User::factory()->create();

    expect($field->editable)->toBeTrue()
        ->and($field->editableBy($user))->toBeTrue();
});

test('a non-editable field is not editable when there is no user at all', function () {
    $field = CustomField::factory()->create(['editable' => false]);

    expect($field->editableBy(null))->toBeFalse();
});

test('an admin can set editable to false when creating a custom field', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Locked field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->set('editable', false)
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::where('name', 'Locked field')->firstOrFail();

    expect($field->editable)->toBeFalse();
});

test('filterEditableValues strips values for fields the user cannot edit and keeps the rest', function () {
    $lockedField = CustomField::factory()->create(['editable' => false]);
    $openField = CustomField::factory()->create();
    $fields = collect([$lockedField, $openField]);
    $user = User::factory()->create();

    $filtered = CustomField::filterEditableValues($fields, [
        $lockedField->id => 'tampered',
        $openField->id => 'fine',
    ], $user);

    expect($filtered)->toBe([$openField->id => 'fine']);
});

test('filterEditableValues keeps every value for an admin', function () {
    $lockedField = CustomField::factory()->create(['editable' => false]);
    $fields = collect([$lockedField]);
    $admin = User::factory()->admin()->create();

    $filtered = CustomField::filterEditableValues($fields, [$lockedField->id => 'value'], $admin);

    expect($filtered)->toBe([$lockedField->id => 'value']);
});

test('a value submitted for a non-editable custom field is not persisted, even bypassing the disabled UI', function () {
    $field = CustomField::factory()->create([
        'name' => 'Locked field',
        'customized_type' => CustomizableType::Project->value,
        'editable' => false,
    ]);
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $project->setCustomFieldValues([$field->id => 'original']);

    $member = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['edit_project']]);
    Member::factory()->for($project)->for($member)->create()->roles()->attach($role);

    Livewire::actingAs($member)
        ->test('projects.form', ['project' => $project])
        ->set("customFieldValues.{$field->id}", 'tampered')
        ->call('save');

    expect($project->fresh()->customValue($field))->toBe('original');
});

test('editable defaults to true when left unset', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Default editable field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::where('name', 'Default editable field')->firstOrFail();

    expect($field->editable)->toBeTrue();
});
