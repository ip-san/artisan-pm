<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Enums\VersionSharing;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function documentCustomFieldMember(Project $project, array $permissions = ['view_documents', 'add_documents', 'edit_documents']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('creating a document persists its custom field values', function () {
    $project = Project::factory()->create();
    $user = documentCustomFieldMember($project);
    $field = CustomField::factory()->create(['name' => 'Reference URL', 'customized_type' => CustomizableType::Document->value]);

    Livewire::actingAs($user)
        ->test('documents.form', ['project' => $project])
        ->set('title', 'Spec Sheet')
        ->set("customFieldValues.{$field->id}", 'https://example.com/spec')
        ->call('save')
        ->assertRedirect();

    $document = Document::where('title', 'Spec Sheet')->firstOrFail();

    expect($document->customValue($field))->toBe('https://example.com/spec');
});

test('a required document custom field blocks submission when left blank', function () {
    $project = Project::factory()->create();
    $user = documentCustomFieldMember($project);
    CustomField::factory()->required()->create(['customized_type' => CustomizableType::Document->value, 'name' => 'Required field']);
    $field = CustomField::where('name', 'Required field')->firstOrFail();

    Livewire::actingAs($user)
        ->test('documents.form', ['project' => $project])
        ->set('title', 'Missing Required Field')
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$field->id}"]);
});

test('editing a document preloads and updates its existing custom field value, and shows it on the document page', function () {
    $project = Project::factory()->create();
    $user = documentCustomFieldMember($project);
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Document->value, 'name' => 'Approved by']);
    $document = Document::factory()->for($project)->create();
    $document->setCustomFieldValues([$field->id => 'initial']);

    $component = Livewire::actingAs($user)->test('documents.form', ['project' => $project, 'document' => $document]);
    expect($component->get('customFieldValues')[$field->id])->toBe('initial');

    $component->set("customFieldValues.{$field->id}", 'updated')->call('save')->assertRedirect();

    expect($document->fresh()->customValue($field))->toBe('updated');

    Livewire::actingAs($user)
        ->test('documents.show', ['project' => $project, 'document' => $document->fresh()])
        ->assertSee('Approved by')
        ->assertSee('updated');
});

test('an issue custom field is neither rendered nor saved on a document form', function () {
    $project = Project::factory()->create();
    $user = documentCustomFieldMember($project);
    $issueField = CustomField::factory()->create(['name' => 'Issue-only field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($user)
        ->test('documents.form', ['project' => $project])
        ->set('title', 'Plain Document')
        ->assertDontSee('Issue-only field')
        ->call('save')
        ->assertRedirect();

    $document = Document::where('title', 'Plain Document')->firstOrFail();

    expect($document->customFieldValues()->count())->toBe(0)
        ->and($issueField->exists)->toBeTrue();
});

test('the document form rejects a user or version outside the project', function () {
    $project = Project::factory()->create();
    $user = documentCustomFieldMember($project);
    $outsider = User::factory()->create(['name' => 'Olga Outsider']);
    $userField = CustomField::factory()->create(['field_format' => CustomFieldFormat::User->value, 'customized_type' => CustomizableType::Document->value, 'name' => 'Reviewer']);
    $versionField = CustomField::factory()->create(['field_format' => CustomFieldFormat::Version->value, 'customized_type' => CustomizableType::Document->value, 'name' => 'Target version']);
    $foreignVersion = Version::factory()->for(Project::factory()->create())->create(['sharing' => VersionSharing::None]);
    $ownVersion = Version::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('documents.form', ['project' => $project])
        ->set('title', 'Cross-project attempt')
        ->set("customFieldValues.{$userField->id}", (string) $outsider->id)
        ->set("customFieldValues.{$versionField->id}", (string) $foreignVersion->id)
        ->call('save')
        ->assertHasErrors(["customFieldValues.{$userField->id}", "customFieldValues.{$versionField->id}"]);

    Livewire::actingAs($user)
        ->test('documents.form', ['project' => $project])
        ->set('title', 'Same-project values')
        ->set("customFieldValues.{$userField->id}", (string) $user->id)
        ->set("customFieldValues.{$versionField->id}", (string) $ownVersion->id)
        ->call('save')
        ->assertHasNoErrors();

    $document = Document::where('title', 'Same-project values')->firstOrFail();
    expect($document->customValue($userField))->toBe($user->displayName())
        ->and($document->customValue($versionField))->toBe($ownVersion->name);
});

test('a document custom field is only visible to roles it is scoped to', function () {
    $project = Project::factory()->create();
    $visibleRole = Role::factory()->create(['permissions' => ['view_documents', 'add_documents']]);
    $hiddenRole = Role::factory()->create(['permissions' => ['view_documents', 'add_documents']]);

    $field = CustomField::factory()->create(['name' => 'Restricted field', 'customized_type' => CustomizableType::Document->value]);
    $field->roles()->attach($visibleRole);

    $visibleUser = User::factory()->create();
    Member::factory()->for($project)->for($visibleUser)->create()->roles()->attach($visibleRole);

    $hiddenUser = User::factory()->create();
    Member::factory()->for($project)->for($hiddenUser)->create()->roles()->attach($hiddenRole);

    Livewire::actingAs($visibleUser)
        ->test('documents.form', ['project' => $project])
        ->assertSee('Restricted field');

    Livewire::actingAs($hiddenUser)
        ->test('documents.form', ['project' => $project])
        ->assertDontSee('Restricted field');
});
