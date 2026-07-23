<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryCommitter;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function repositoryCommitterManager(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_changesets', 'manage_repository']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('a manager can view existing committer mappings', function () {
    $project = Project::factory()->create();
    $manager = repositoryCommitterManager($project);
    $repository = Repository::factory()->for($project)->create();
    $mappedUser = User::factory()->create(['name' => 'Jane Example']);
    RepositoryCommitter::factory()->for($repository)->for($mappedUser)->create(['committer' => 'Jane Doe <jane@old-corp.com>']);

    Livewire::actingAs($manager)
        ->test('repository.committers', ['project' => $project])
        ->assertSee('Jane Doe <jane@old-corp.com>')
        ->assertSee('Jane Example');
});

test('a manager can add a new committer mapping', function () {
    $project = Project::factory()->create();
    $manager = repositoryCommitterManager($project);
    $repository = Repository::factory()->for($project)->create();
    $targetUser = User::factory()->create();
    Member::factory()->for($project)->for($targetUser)->create();

    Livewire::actingAs($manager)
        ->test('repository.committers', ['project' => $project])
        ->set('committer', 'Jane Doe <jane@old-corp.com>')
        ->set('userId', $targetUser->id)
        ->call('addMapping')
        ->assertHasNoErrors();

    expect(RepositoryCommitter::where('repository_id', $repository->id)->where('committer', 'Jane Doe <jane@old-corp.com>')->first()?->user_id)
        ->toBe($targetUser->id);
});

test('the same committer string cannot be mapped twice on one repository', function () {
    $project = Project::factory()->create();
    $manager = repositoryCommitterManager($project);
    $repository = Repository::factory()->for($project)->create();
    RepositoryCommitter::factory()->for($repository)->create(['committer' => 'Jane Doe <jane@old-corp.com>']);
    $targetUser = User::factory()->create();

    Livewire::actingAs($manager)
        ->test('repository.committers', ['project' => $project])
        ->set('committer', 'Jane Doe <jane@old-corp.com>')
        ->set('userId', $targetUser->id)
        ->call('addMapping')
        ->assertHasErrors(['committer']);
});

test('a manager can delete a committer mapping', function () {
    $project = Project::factory()->create();
    $manager = repositoryCommitterManager($project);
    $repository = Repository::factory()->for($project)->create();
    $mapping = RepositoryCommitter::factory()->for($repository)->create();

    Livewire::actingAs($manager)
        ->test('repository.committers', ['project' => $project])
        ->call('deleteMapping', $mapping->id);

    expect(RepositoryCommitter::find($mapping->id))->toBeNull();
});

test('a member without manage_repository cannot access the committers screen', function () {
    $project = Project::factory()->create();
    Repository::factory()->for($project)->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_changesets']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('repository.committers', ['project' => $project])
        ->assertForbidden();
});

test('the committers screen 404s when the project has no repository configured', function () {
    $project = Project::factory()->create();
    $manager = repositoryCommitterManager($project);

    Livewire::actingAs($manager)
        ->test('repository.committers', ['project' => $project])
        ->assertNotFound();
});
