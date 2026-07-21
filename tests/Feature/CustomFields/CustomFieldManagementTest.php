<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create a custom field scoped to trackers', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Client email')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertRedirect(route('custom-fields.index'));

    $field = CustomField::where('name', 'Client email')->firstOrFail();

    expect($field->field_format)->toBe(CustomFieldFormat::String)
        ->and($field->trackers->pluck('id')->all())->toBe([$tracker->id]);
});

test('a list custom field parses its possible values from one-per-line text', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Severity')
        ->set('field_format', CustomFieldFormat::List->value)
        ->set('possibleValuesText', "Low\nMedium\nHigh")
        ->set('trackerIds', [$tracker->id])
        ->call('save');

    $field = CustomField::where('name', 'Severity')->firstOrFail();

    expect($field->possible_values)->toBe(['Low', 'Medium', 'High']);
});

test('a non-admin cannot access custom field administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('custom-fields.index')->assertForbidden();
    Livewire::actingAs($user)->test('custom-fields.form')->assertForbidden();
});

test('an admin can restrict a custom field to specific roles', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Restricted field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->set('roleIds', [$role->id])
        ->call('save');

    $field = CustomField::where('name', 'Restricted field')->firstOrFail();

    expect($field->roles->pluck('id')->all())->toBe([$role->id]);
});

test('a custom field must be attached to at least one tracker', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Orphan field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->call('save')
        ->assertHasErrors(['trackerIds']);
});

test('an admin can create a project custom field without tracker scoping', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Budget code')
        ->set('customized_type', CustomizableType::Project->value)
        ->set('field_format', CustomFieldFormat::String->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('custom-fields.index'));

    $field = CustomField::where('name', 'Budget code')->firstOrFail();

    expect($field->customized_type)->toBe(CustomizableType::Project)
        ->and($field->trackers)->toBeEmpty();
});

test('a project custom field cannot have its type changed after creation', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Project->value, 'name' => 'Original']);

    Livewire::actingAs($admin)
        ->test('custom-fields.form', ['customField' => $field])
        ->set('customized_type', CustomizableType::Issue->value)
        ->call('save');

    expect($field->refresh()->customized_type)->toBe(CustomizableType::Project);
});

test('an admin can set default_value, regexp, and searchable', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Ticket code')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->set('default_value', 'N/A')
        ->set('regexp', '^[A-Z]{2}\d{4}$')
        ->set('searchable', true)
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::where('name', 'Ticket code')->firstOrFail();

    expect($field->default_value)->toBe('N/A')
        ->and($field->regexp)->toBe('^[A-Z]{2}\d{4}$')
        ->and($field->searchable)->toBeTrue();
});

test('an invalid regular expression is rejected', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Broken regex field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->set('regexp', '[unterminated')
        ->call('save')
        ->assertHasErrors(['regexp']);

    expect(CustomField::where('name', 'Broken regex field')->exists())->toBeFalse();
});

test('leaving default_value and regexp blank stores null, not empty string', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Plain field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('trackerIds', [$tracker->id])
        ->call('save');

    $field = CustomField::where('name', 'Plain field')->firstOrFail();

    expect($field->default_value)->toBeNull()
        ->and($field->regexp)->toBeNull();
});

test('a new issue is prefilled with its custom fields default_value', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['default_status_id' => IssueStatus::factory()->create()->id]);
    $project->trackers()->attach($tracker);
    $field = CustomField::factory()->create(['name' => 'Client email', 'default_value' => 'unknown@example.com']);
    $field->trackers()->attach($tracker);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id);

    expect($component->get('customFieldValues')[$field->id])->toBe('unknown@example.com');
});

test('a custom field with no default_value leaves the new issue input blank', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['default_status_id' => IssueStatus::factory()->create()->id]);
    $project->trackers()->attach($tracker);
    $field = CustomField::factory()->create(['name' => 'No default', 'default_value' => null]);
    $field->trackers()->attach($tracker);

    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id);

    expect($component->get('customFieldValues'))->not->toHaveKey($field->id);
});
