<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('creating a project persists its custom field values', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['name' => 'Budget code', 'customized_type' => CustomizableType::Project->value]);

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'New Project')
        ->set('identifier', 'new-project')
        ->set("customFieldValues.{$field->id}", 'BC-42')
        ->call('save')
        ->assertRedirect();

    $project = Project::where('identifier', 'new-project')->firstOrFail();

    expect($project->customValue($field))->toBe('BC-42');
});

test('a required project custom field blocks submission when left blank', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::Project->value, 'name' => 'Required field']);

    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'Missing Required Field')
        ->set('identifier', 'missing-required')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a project preloads and updates its existing custom field value', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Project->value]);
    $project = Project::factory()->create();
    $project->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($admin)->test('projects.form', ['project' => $project]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($project->fresh()->customValue($field))->toBe('updated');
});

test('an issue custom field is neither rendered nor saved on a project form', function () {
    $admin = User::factory()->admin()->create();
    $issueField = CustomField::factory()->create(['name' => 'Issue-only field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'Plain Project')
        ->set('identifier', 'plain-project')
        ->assertDontSee('Issue-only field')
        ->call('save')
        ->assertRedirect();

    $project = Project::where('identifier', 'plain-project')->firstOrFail();

    expect($project->customFieldValues()->count())->toBe(0)
        ->and($issueField->exists)->toBeTrue();
});
