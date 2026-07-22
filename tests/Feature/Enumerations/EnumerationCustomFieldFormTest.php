<?php

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\User;
use Livewire\Livewire;

test('creating a time entry activity persists its custom field values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['name' => 'Billing code', 'customized_type' => CustomizableType::TimeEntryActivity->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::TimeEntryActivity])
        ->set('name', 'Design')
        ->set("customFieldValues.{$field->id}", 'BC-9')
        ->call('save')
        ->assertRedirect();

    $activity = Enumeration::where('name', 'Design')->firstOrFail();

    expect($activity->customValue($field))->toBe('BC-9');
});

test('a required time entry activity custom field blocks submission when left blank', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::TimeEntryActivity->value, 'name' => 'Required field']);
    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::TimeEntryActivity])
        ->set('name', 'Missing Required Field')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a time entry activity preloads and updates its existing custom field value', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::TimeEntryActivity->value]);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $activity->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($admin)->test('enumerations.form', ['type' => EnumerationType::TimeEntryActivity, 'enumeration' => $activity]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($activity->fresh()->customValue($field))->toBe('updated');
});

test('a time entry activity custom field is neither rendered nor saved for an issue priority', function () {
    $admin = User::factory()->admin()->create();
    $activityField = CustomField::factory()->create(['name' => 'Activity-only field', 'customized_type' => CustomizableType::TimeEntryActivity->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::IssuePriority])
        ->set('name', 'Plain Priority')
        ->assertDontSee('Activity-only field')
        ->call('save')
        ->assertRedirect();

    $priority = Enumeration::where('name', 'Plain Priority')->firstOrFail();

    expect($priority->customFieldValues()->count())->toBe(0)
        ->and($activityField->exists)->toBeTrue();
});

test('an issue custom field is neither rendered nor saved on an enumeration form', function () {
    $admin = User::factory()->admin()->create();
    $issueField = CustomField::factory()->create(['name' => 'Issue-only field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::TimeEntryActivity])
        ->set('name', 'Plain Activity')
        ->assertDontSee('Issue-only field')
        ->call('save')
        ->assertRedirect();

    $activity = Enumeration::where('name', 'Plain Activity')->firstOrFail();

    expect($activity->customFieldValues()->count())->toBe(0)
        ->and($issueField->exists)->toBeTrue();
});
