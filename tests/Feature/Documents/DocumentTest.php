<?php

use App\Enums\EnumerationType;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

test('documents default to being grouped by category, with each group sorted by title', function () {
    $project = Project::factory()->create();
    $user = documentMember($project);
    $specs = Enumeration::factory()->create(['type' => EnumerationType::DocumentCategory->value, 'name' => 'Specs']);
    $guides = Enumeration::factory()->create(['type' => EnumerationType::DocumentCategory->value, 'name' => 'Guides']);
    Document::factory()->for($project)->create(['category_id' => $specs->id, 'title' => 'Zeta spec']);
    Document::factory()->for($project)->create(['category_id' => $specs->id, 'title' => 'Alpha spec']);
    Document::factory()->for($project)->create(['category_id' => $guides->id, 'title' => 'User guide']);
    Document::factory()->for($project)->create(['category_id' => null, 'title' => 'Uncategorized doc']);

    $groups = Livewire::actingAs($user)
        ->test('documents.index', ['project' => $project])
        ->get('groupedDocuments');

    expect($groups->keys()->all())->toBe(['', 'Guides', 'Specs'])
        ->and($groups['Specs']->pluck('title')->all())->toBe(['Alpha spec', 'Zeta spec']);
});

test('sorting by date groups documents by their last-updated date, newest group first', function () {
    $project = Project::factory()->create();
    $user = documentMember($project);
    $older = Document::factory()->for($project)->create(['title' => 'Older doc']);
    $older->timestamps = false;
    $older->forceFill(['updated_at' => now()->subDays(2)])->save();
    $newer = Document::factory()->for($project)->create(['title' => 'Newer doc']);

    $groups = Livewire::actingAs($user)
        ->test('documents.index', ['project' => $project])
        ->set('sortBy', 'date')
        ->get('groupedDocuments');

    expect($groups->keys()->all())->toBe([
        $newer->fresh()->updated_at->toDateString(),
        $older->fresh()->updated_at->toDateString(),
    ]);
});

test('sorting by title groups documents by the first letter of their title', function () {
    $project = Project::factory()->create();
    $user = documentMember($project);
    Document::factory()->for($project)->create(['title' => 'Beta notes']);
    Document::factory()->for($project)->create(['title' => 'Another doc']);
    Document::factory()->for($project)->create(['title' => 'Alpha notes']);

    $groups = Livewire::actingAs($user)
        ->test('documents.index', ['project' => $project])
        ->set('sortBy', 'title')
        ->get('groupedDocuments');

    expect($groups->keys()->all())->toBe(['A', 'B'])
        ->and($groups['A']->pluck('title')->all())->toBe(['Alpha notes', 'Another doc']);
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

test('a member with edit_documents can set an attachment description', function () {
    $project = Project::factory()->create();
    $editor = documentMember($project, ['view_documents', 'edit_documents']);
    $document = Document::factory()->for($project)->create();
    $media = $document->addMedia(UploadedFile::fake()->create('spec.pdf', 200))->toMediaCollection('attachments');

    Livewire::actingAs($editor)
        ->test('documents.show', ['project' => $project, 'document' => $document])
        ->set("attachmentDescriptions.{$media->id}", 'Full specification')
        ->call('updateAttachmentDescription', $media->id);

    expect($media->fresh()->getCustomProperty('description'))->toBe('Full specification');
});

test('a member without edit_documents cannot set an attachment description', function () {
    $project = Project::factory()->create();
    $viewer = documentMember($project);
    $document = Document::factory()->for($project)->create();
    $media = $document->addMedia(UploadedFile::fake()->create('spec.pdf', 200))->toMediaCollection('attachments');

    Livewire::actingAs($viewer)
        ->test('documents.show', ['project' => $project, 'document' => $document])
        ->set("attachmentDescriptions.{$media->id}", 'sneaky')
        ->call('updateAttachmentDescription', $media->id)
        ->assertForbidden();

    expect($media->fresh()->getCustomProperty('description'))->toBeNull();
});
