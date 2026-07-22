<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Group;
use App\Models\User;
use Livewire\Livewire;

test('creating a group persists its custom field values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['name' => 'Cost center', 'customized_type' => CustomizableType::Group->value]);

    Livewire::actingAs($admin)
        ->test('groups.form')
        ->set('name', 'Support Team')
        ->set("customFieldValues.{$field->id}", 'CC-42')
        ->call('save')
        ->assertRedirect();

    $group = Group::where('name', 'Support Team')->firstOrFail();

    expect($group->customValue($field))->toBe('CC-42');
});

test('a required group custom field blocks submission when left blank', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::Group->value, 'name' => 'Required field']);
    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('groups.form')
        ->set('name', 'Missing Required Field')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a group preloads and updates its existing custom field value', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Group->value]);
    $group = Group::factory()->create();
    $group->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($admin)->test('groups.form', ['group' => $group]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($group->fresh()->customValue($field))->toBe('updated');
});

test('an issue custom field is neither rendered nor saved on a group form', function () {
    $admin = User::factory()->admin()->create();
    $issueField = CustomField::factory()->create(['name' => 'Issue-only field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($admin)
        ->test('groups.form')
        ->set('name', 'Plain Group')
        ->assertDontSee('Issue-only field')
        ->call('save')
        ->assertRedirect();

    $group = Group::where('name', 'Plain Group')->firstOrFail();

    expect($group->customFieldValues()->count())->toBe(0)
        ->and($issueField->exists)->toBeTrue();
});

test('a non-admin cannot see or submit group custom fields', function () {
    $user = User::factory()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Group->value]);

    Livewire::actingAs($user)
        ->test('groups.form')
        ->assertForbidden();

    expect($field->exists)->toBeTrue();
});
