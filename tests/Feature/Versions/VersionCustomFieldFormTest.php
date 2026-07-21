<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function versionCustomFieldMember(Project $project, array $permissions = ['manage_versions']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('creating a version persists its custom field values', function () {
    $project = Project::factory()->create();
    $user = versionCustomFieldMember($project);
    $field = CustomField::factory()->create(['name' => 'Release notes URL', 'customized_type' => CustomizableType::Version->value]);

    Livewire::actingAs($user)
        ->test('versions.form', ['project' => $project])
        ->set('name', '1.0.0')
        ->set("customFieldValues.{$field->id}", 'https://example.com/notes')
        ->call('save')
        ->assertRedirect();

    $version = Version::where('name', '1.0.0')->firstOrFail();

    expect($version->customValue($field))->toBe('https://example.com/notes');
});

test('a required version custom field blocks submission when left blank', function () {
    $project = Project::factory()->create();
    $user = versionCustomFieldMember($project);
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::Version->value, 'name' => 'Required field']);
    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($user)
        ->test('versions.form', ['project' => $project])
        ->set('name', 'Missing Required Field')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a version preloads and updates its existing custom field value', function () {
    $project = Project::factory()->create();
    $user = versionCustomFieldMember($project);
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Version->value]);
    $version = Version::factory()->for($project)->create();
    $version->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($user)->test('versions.form', ['project' => $project, 'version' => $version]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($version->fresh()->customValue($field))->toBe('updated');
});

test('an issue custom field is neither rendered nor saved on a version form', function () {
    $project = Project::factory()->create();
    $user = versionCustomFieldMember($project);
    $issueField = CustomField::factory()->create(['name' => 'Issue-only field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($user)
        ->test('versions.form', ['project' => $project])
        ->set('name', 'Plain Version')
        ->assertDontSee('Issue-only field')
        ->call('save')
        ->assertRedirect();

    $version = Version::where('name', 'Plain Version')->firstOrFail();

    expect($version->customFieldValues()->count())->toBe(0)
        ->and($issueField->exists)->toBeTrue();
});

test('a version custom field is only visible to roles it is scoped to', function () {
    $project = Project::factory()->create();
    $visibleRole = Role::factory()->create(['permissions' => ['manage_versions']]);
    $hiddenRole = Role::factory()->create(['permissions' => ['manage_versions']]);

    $field = CustomField::factory()->create(['name' => 'Restricted field', 'customized_type' => CustomizableType::Version->value]);
    $field->roles()->attach($visibleRole);

    $visibleUser = User::factory()->create();
    Member::factory()->for($project)->for($visibleUser)->create()->roles()->attach($visibleRole);

    $hiddenUser = User::factory()->create();
    Member::factory()->for($project)->for($hiddenUser)->create()->roles()->attach($hiddenRole);

    Livewire::actingAs($visibleUser)
        ->test('versions.form', ['project' => $project])
        ->assertSee('Restricted field');

    Livewire::actingAs($hiddenUser)
        ->test('versions.form', ['project' => $project])
        ->assertDontSee('Restricted field');
});
