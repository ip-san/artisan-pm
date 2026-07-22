<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function fileHistoryMember(Project $project, array $permissions = ['browse_repository', 'view_changesets']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function createFileHistoryGitRepo(): string
{
    $path = config('scm.repositories_root').'/file-history-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    file_put_contents("{$path}/README.md", "first version\n");
    file_put_contents("{$path}/unrelated.txt", "unrelated\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);

    file_put_contents("{$path}/README.md", "second version\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Update README']);

    file_put_contents("{$path}/unrelated.txt", "changed again\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Touch unrelated file only']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'file-history-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('a member with browse_repository sees every changeset that touched a given file, and none that did not', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $comments = Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'README.md'])
        ->get('changesets')
        ->pluck('comments');

    expect($comments)->toContain('Initial commit', 'Update README')
        ->not->toContain('Touch unrelated file only');
});

test('a member without browse_repository is forbidden from viewing file history', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project, ['view_changesets']);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'README.md'])
        ->assertForbidden();
});

test('a file with no history shows an empty state', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'never-existed.txt'])
        ->assertSee('見つかりませんでした');
});
