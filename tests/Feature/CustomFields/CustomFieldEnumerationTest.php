<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomFieldValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create an enumeration custom field with managed options', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Severity')
        ->set('field_format', CustomFieldFormat::Enumeration->value)
        ->set('trackerIds', [$tracker->id])
        ->call('addEnumerationOption')
        ->set('enumerationOptions.0.name', 'Low')
        ->call('addEnumerationOption')
        ->set('enumerationOptions.1.name', 'High')
        ->call('save')
        ->assertRedirect(route('custom-fields.index'));

    $field = CustomField::where('name', 'Severity')->firstOrFail();

    expect($field->field_format)->toBe(CustomFieldFormat::Enumeration)
        ->and($field->enumerationOptions->pluck('name')->all())->toBe(['Low', 'High'])
        ->and($field->enumerationOptions->pluck('active')->all())->toBe([true, true]);
});

test('editing renames an existing option and can deactivate it without deleting it', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $field->trackers()->attach($tracker);
    $option = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Draft']);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->set('enumerationOptions.0.name', 'Archived')
        ->set('enumerationOptions.0.active', false)
        ->call('save');

    expect($option->fresh()->name)->toBe('Archived')
        ->and($option->fresh()->active)->toBeFalse();
});

test('deleting an option with a reassignment target moves existing values to it', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $old = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Old']);
    $replacement = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Replacement']);

    $value = CustomFieldValue::create([
        'custom_field_id' => $field->id,
        'customized_type' => 'issue',
        'customized_id' => 1,
        'value_string' => (string) $old->id,
    ]);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->set('enumerationOptions.0.reassignTo', (string) $replacement->id)
        ->call('deleteEnumerationOption', 0);

    expect(CustomFieldEnumeration::find($old->id))->toBeNull()
        ->and($value->fresh()->value_string)->toBe((string) $replacement->id);
});

test('deleting an option with no reassignment target clears existing values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $option = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Old']);

    $value = CustomFieldValue::create([
        'custom_field_id' => $field->id,
        'customized_type' => 'issue',
        'customized_id' => 1,
        'value_string' => (string) $option->id,
    ]);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->call('deleteEnumerationOption', 0);

    expect(CustomFieldEnumeration::find($option->id))->toBeNull()
        ->and($value->fresh()->value_string)->toBeNull();
});

test('an issue with an enumeration custom field displays the selected option\'s name', function () {
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $field->trackers()->attach($tracker);
    $option = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'High priority']);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Issue with an enumeration field')
        ->set("customFieldValues.{$field->id}", (string) $option->id)
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Issue with an enumeration field')->firstOrFail();

    expect($issue->customValue($field))->toBe('High priority');
});

test('submitting an inactive option id for an enumeration custom field fails validation', function () {
    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $field->trackers()->attach($tracker);
    $inactiveOption = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['active' => false]);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Issue with a bad custom field value')
        ->set("customFieldValues.{$field->id}", (string) $inactiveOption->id)
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('moving an option up swaps its position with the previous option', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $first = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'First', 'position' => 1]);
    $second = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Second', 'position' => 2]);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->call('moveEnumerationOptionUp', 1);

    expect($first->fresh()->position)->toBe(2)
        ->and($second->fresh()->position)->toBe(1)
        ->and($field->enumerationOptions()->pluck('name')->all())->toBe(['Second', 'First']);
});

test('moving an option down swaps its position with the next option', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $first = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'First', 'position' => 1]);
    $second = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Second', 'position' => 2]);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->call('moveEnumerationOptionDown', 0);

    expect($first->fresh()->position)->toBe(2)
        ->and($second->fresh()->position)->toBe(1)
        ->and($field->enumerationOptions()->pluck('name')->all())->toBe(['Second', 'First']);
});

test('moving the first option up does nothing', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $first = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'First', 'position' => 1]);
    CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Second', 'position' => 2]);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->call('moveEnumerationOptionUp', 0);

    expect($first->fresh()->position)->toBe(1);
});

test('moving the last persisted option down does nothing even with an unsaved new row appended', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Enumeration->value]);
    $first = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'First', 'position' => 1]);
    $second = CustomFieldEnumeration::factory()->for($field, 'customField')->create(['name' => 'Second', 'position' => 2]);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->call('addEnumerationOption')
        ->call('moveEnumerationOptionDown', 1);

    expect($first->fresh()->position)->toBe(1)
        ->and($second->fresh()->position)->toBe(2);
});
