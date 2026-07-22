<?php

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\User;
use Livewire\Livewire;

test('creating a document category persists its custom field values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['name' => 'Retention years', 'customized_type' => CustomizableType::DocumentCategory->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::DocumentCategory])
        ->set('name', 'Specification')
        ->set("customFieldValues.{$field->id}", '5')
        ->call('save')
        ->assertRedirect();

    $category = Enumeration::where('name', 'Specification')->firstOrFail();

    expect($category->customValue($field))->toBe('5');
});

test('a required document category custom field blocks submission when left blank', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::DocumentCategory->value, 'name' => 'Required field']);
    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::DocumentCategory])
        ->set('name', 'Missing Required Field')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a document category preloads and updates its existing custom field value', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::DocumentCategory->value]);
    $category = Enumeration::factory()->create(['type' => EnumerationType::DocumentCategory->value]);
    $category->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($admin)->test('enumerations.form', ['type' => EnumerationType::DocumentCategory, 'enumeration' => $category]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($category->fresh()->customValue($field))->toBe('updated');
});

test('a document category custom field is neither rendered nor saved for an issue priority', function () {
    $admin = User::factory()->admin()->create();
    $categoryField = CustomField::factory()->create(['name' => 'Category-only field', 'customized_type' => CustomizableType::DocumentCategory->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::IssuePriority])
        ->set('name', 'Plain Priority')
        ->assertDontSee('Category-only field')
        ->call('save')
        ->assertRedirect();

    $priority = Enumeration::where('name', 'Plain Priority')->firstOrFail();

    expect($priority->customFieldValues()->count())->toBe(0)
        ->and($categoryField->exists)->toBeTrue();
});

test('a document category custom field is not shared with time entry activity, and vice versa', function () {
    $admin = User::factory()->admin()->create();
    $categoryField = CustomField::factory()->create(['name' => 'Category-only field', 'customized_type' => CustomizableType::DocumentCategory->value]);
    $activityField = CustomField::factory()->create(['name' => 'Activity-only field', 'customized_type' => CustomizableType::TimeEntryActivity->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::DocumentCategory])
        ->assertSee('Category-only field')
        ->assertDontSee('Activity-only field');

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::TimeEntryActivity])
        ->assertSee('Activity-only field')
        ->assertDontSee('Category-only field');
});
