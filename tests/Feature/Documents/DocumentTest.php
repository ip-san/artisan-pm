<?php

use App\Models\Document;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function documentMember(Project $project, array $permissions = ['view_documents']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view_documents can see the document list and a document', function () {
    $project = Project::factory()->create();
    $user = documentMember($project);
    $document = Document::factory()->for($project)->create();

    Livewire::actingAs($user)->test('documents.index', ['project' => $project])->assertOk();
    Livewire::actingAs($user)->test('documents.show', ['project' => $project, 'document' => $document])->assertOk();
});

test('add_documents is required to create a document', function () {
    $project = Project::factory()->create();
    $viewer = documentMember($project);
    $adder = documentMember($project, ['view_documents', 'add_documents']);

    Livewire::actingAs($viewer)->test('documents.form', ['project' => $project])->assertForbidden();

    Livewire::actingAs($adder)
        ->test('documents.form', ['project' => $project])
        ->set('title', 'Spec sheet')
        ->call('save');

    expect(Document::where('title', 'Spec sheet')->exists())->toBeTrue();
});

test('edit_documents and delete_documents are checked independently of add_documents', function () {
    $project = Project::factory()->create();
    $editor = documentMember($project, ['view_documents', 'edit_documents']);
    $deleter = documentMember($project, ['view_documents', 'delete_documents']);
    $document = Document::factory()->for($project)->create();

    Livewire::actingAs($editor)
        ->test('documents.form', ['project' => $project, 'document' => $document])
        ->set('title', 'Renamed')
        ->call('save');

    expect($document->fresh()->title)->toBe('Renamed');

    Livewire::actingAs($editor)
        ->test('documents.show', ['project' => $project, 'document' => $document])
        ->call('delete')
        ->assertForbidden();

    Livewire::actingAs($deleter)
        ->test('documents.show', ['project' => $project, 'document' => $document])
        ->call('delete');

    expect(Document::find($document->id))->toBeNull();
});
