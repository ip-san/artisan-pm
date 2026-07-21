<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
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

test('a custom field must be attached to at least one tracker', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('custom-fields.form')
        ->set('name', 'Orphan field')
        ->set('field_format', CustomFieldFormat::String->value)
        ->call('save')
        ->assertHasErrors(['trackerIds']);
});
