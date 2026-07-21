<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function filesMember(Project $project, array $permissions = ['view_files']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view_files can see the files index', function () {
    $project = Project::factory()->create();
    $user = filesMember($project);
    Version::factory()->for($project)->create();

    Livewire::actingAs($user)->test('files.index', ['project' => $project])->assertOk();
});

test('only a member with manage_files can upload a file to a version', function () {
    $project = Project::factory()->create();
    $viewer = filesMember($project);
    $manager = filesMember($project, ['view_files', 'manage_files']);
    $version = Version::factory()->for($project)->create();

    Livewire::actingAs($viewer)
        ->test('files.index', ['project' => $project])
        ->set('version_id', $version->id)
        ->set('newFiles', [UploadedFile::fake()->create('release-notes.pdf', 200)])
        ->call('upload')
        ->assertForbidden();

    expect($version->fresh()->files())->toHaveCount(0);

    Livewire::actingAs($manager)
        ->test('files.index', ['project' => $project])
        ->set('version_id', $version->id)
        ->set('newFiles', [UploadedFile::fake()->create('release-notes.pdf', 200)])
        ->call('upload');

    expect($version->fresh()->files())->toHaveCount(1);
});

test('a member with manage_files can upload a project-level file with no version', function () {
    $project = Project::factory()->create();
    $manager = filesMember($project, ['view_files', 'manage_files']);

    Livewire::actingAs($manager)
        ->test('files.index', ['project' => $project])
        ->set('version_id', null)
        ->set('newFiles', [UploadedFile::fake()->create('readme.txt', 50)])
        ->call('upload');

    expect($project->fresh()->files())->toHaveCount(1);
});

test('a viewer without manage_files cannot upload a project-level file', function () {
    $project = Project::factory()->create();
    $viewer = filesMember($project);

    Livewire::actingAs($viewer)
        ->test('files.index', ['project' => $project])
        ->set('version_id', null)
        ->set('newFiles', [UploadedFile::fake()->create('readme.txt', 50)])
        ->call('upload')
        ->assertForbidden();

    expect($project->fresh()->files())->toHaveCount(0);
});

test('a version belonging to another project is rejected', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $manager = filesMember($project, ['view_files', 'manage_files']);
    $foreignVersion = Version::factory()->for($otherProject)->create();

    Livewire::actingAs($manager)
        ->test('files.index', ['project' => $project])
        ->set('version_id', $foreignVersion->id)
        ->set('newFiles', [UploadedFile::fake()->create('leak.pdf', 200)])
        ->call('upload')
        ->assertHasErrors(['version_id']);

    expect($foreignVersion->fresh()->files())->toHaveCount(0);
});

test('a manager can set a description on a project-level file', function () {
    $project = Project::factory()->create();
    $manager = filesMember($project, ['view_files', 'manage_files']);
    $media = $project->addMedia(UploadedFile::fake()->create('readme.txt', 10))->toMediaCollection('files');

    Livewire::actingAs($manager)
        ->test('files.index', ['project' => $project])
        ->set("attachmentDescriptions.{$media->id}", 'Project overview document')
        ->call('updateAttachmentDescription', $media->id);

    expect($media->fresh()->getCustomProperty('description'))->toBe('Project overview document');
});

test('a manager can set a description on a version file', function () {
    $project = Project::factory()->create();
    $manager = filesMember($project, ['view_files', 'manage_files']);
    $version = Version::factory()->for($project)->create();
    $media = $version->addMedia(UploadedFile::fake()->create('release-notes.pdf', 200))->toMediaCollection('files');

    Livewire::actingAs($manager)
        ->test('files.index', ['project' => $project])
        ->set("attachmentDescriptions.{$media->id}", 'Release notes for 1.0')
        ->call('updateAttachmentDescription', $media->id);

    expect($media->fresh()->getCustomProperty('description'))->toBe('Release notes for 1.0');
});

test('a viewer without manage_files cannot set a file description', function () {
    $project = Project::factory()->create();
    $viewer = filesMember($project);
    $media = $project->addMedia(UploadedFile::fake()->create('readme.txt', 10))->toMediaCollection('files');

    Livewire::actingAs($viewer)
        ->test('files.index', ['project' => $project])
        ->set("attachmentDescriptions.{$media->id}", 'sneaky')
        ->call('updateAttachmentDescription', $media->id)
        ->assertForbidden();

    expect($media->fresh()->getCustomProperty('description'))->toBeNull();
});

test('a file on another project cannot have its description set through this project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $manager = filesMember($project, ['view_files', 'manage_files']);
    Member::factory()->for($otherProject)->for($manager)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_files', 'manage_files']])
    );
    $foreignMedia = $otherProject->addMedia(UploadedFile::fake()->create('secret.txt', 10))->toMediaCollection('files');

    Livewire::actingAs($manager)
        ->test('files.index', ['project' => $project])
        ->set("attachmentDescriptions.{$foreignMedia->id}", 'leaked')
        ->call('updateAttachmentDescription', $foreignMedia->id)
        ->assertStatus(404);

    expect($foreignMedia->fresh()->getCustomProperty('description'))->toBeNull();
});

test('an image uploaded to a project or version file generates a thumb conversion', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();

    $projectMedia = $project->addMedia(UploadedFile::fake()->image('cover.jpg', 400, 300))->toMediaCollection('files');
    $versionMedia = $version->addMedia(UploadedFile::fake()->image('cover.jpg', 400, 300))->toMediaCollection('files');

    expect($projectMedia->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($versionMedia->hasGeneratedConversion('thumb'))->toBeTrue();
});
