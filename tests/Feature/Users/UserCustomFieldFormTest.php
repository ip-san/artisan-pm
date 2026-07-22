<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\User;
use Livewire\Livewire;

test('creating a user persists its custom field values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['name' => 'Employee ID', 'customized_type' => CustomizableType::User->value]);

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'New Hire')
        ->set('email', 'new-hire@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->set("customFieldValues.{$field->id}", 'EMP-42')
        ->call('save')
        ->assertRedirect();

    $user = User::where('email', 'new-hire@example.com')->firstOrFail();

    expect($user->customValue($field))->toBe('EMP-42');
});

test('a required user custom field blocks submission when left blank', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::User->value, 'name' => 'Required field']);
    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'Missing Required Field')
        ->set('email', 'missing-required@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a user preloads and updates its existing custom field value', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::User->value]);
    $user = User::factory()->create();
    $user->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($admin)->test('users.form', ['user' => $user]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($user->fresh()->customValue($field))->toBe('updated');
});

test('an issue custom field is neither rendered nor saved on a user form', function () {
    $admin = User::factory()->admin()->create();
    $issueField = CustomField::factory()->create(['name' => 'Issue-only field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($admin)
        ->test('users.form')
        ->set('name', 'Plain User')
        ->set('email', 'plain-user@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->assertDontSee('Issue-only field')
        ->call('save')
        ->assertRedirect();

    $user = User::where('email', 'plain-user@example.com')->firstOrFail();

    expect($user->customFieldValues()->count())->toBe(0)
        ->and($issueField->exists)->toBeTrue();
});

test('a non-admin cannot see or submit user custom fields', function () {
    $user = User::factory()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::User->value]);

    Livewire::actingAs($user)
        ->test('users.form')
        ->assertForbidden();

    expect($field->exists)->toBeTrue();
});
