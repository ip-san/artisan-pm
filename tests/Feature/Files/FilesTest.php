<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Illuminate\Http\UploadedFile;
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
