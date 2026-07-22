<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function compareMember(Project $project, array $permissions = ['view_changesets']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * Three commits: base file, a change to it, then a new file — so the
 * range diff between commit 1 and commit 3 must contain both later
 * changes but nothing from the initial snapshot itself.
 */
function createCompareGitRepo(): string
{
    $path = config('scm.repositories_root').'/compare-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    file_put_contents("{$path}/README.md", "original line\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'First commit']);

    file_put_contents("{$path}/README.md", "changed line\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Second commit']);

    file_put_contents("{$path}/extra.txt", "brand new file\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Third commit']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'compare-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('comparing two revisions shows the combined changes between their snapshots', function () {
    $project = Project::factory()->create();
    $user = compareMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createCompareGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $revisions = $repository->changesets()->reorder('id')->pluck('revision');

    $component = Livewire::actingAs($user)
        ->withQueryParams(['from' => $revisions[0], 'to' => $revisions[2]])
        ->test('repository.compare', ['project' => $project]);

    $diff = $component->get('diff');

    expect($diff)->toContain('changed line')
        ->toContain('brand new file')
        ->toContain('-original line');
});

test('endpoints are normalized so the older revision is always the diff base', function () {
    $project = Project::factory()->create();
    $user = compareMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createCompareGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $revisions = $repository->changesets()->reorder('id')->pluck('revision');

    // Reversed input: from = newest, to = oldest.
    $component = Livewire::actingAs($user)
        ->withQueryParams(['from' => $revisions[2], 'to' => $revisions[0]])
        ->test('repository.compare', ['project' => $project]);

    expect($component->get('fromChangeset')->revision)->toBe($revisions[0])
        ->and($component->get('toChangeset')->revision)->toBe($revisions[2])
        ->and($component->get('diff'))->toContain('+changed line');
});

test('an unknown revision or identical endpoints return 404', function () {
    $project = Project::factory()->create();
    $user = compareMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createCompareGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $revision = $repository->changesets()->reorder('id')->pluck('revision')->first();

    Livewire::actingAs($user)
        ->withQueryParams(['from' => $revision, 'to' => 'not-a-revision'])
        ->test('repository.compare', ['project' => $project])
        ->assertStatus(404);

    Livewire::actingAs($user)
        ->withQueryParams(['from' => $revision, 'to' => $revision])
        ->test('repository.compare', ['project' => $project])
        ->assertStatus(404);
});

test('a member without view_changesets cannot compare revisions', function () {
    $project = Project::factory()->create();
    $user = compareMember($project, []);
    $repository = Repository::factory()->for($project)->create(['path' => createCompareGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $revisions = $repository->changesets()->reorder('id')->pluck('revision');

    Livewire::actingAs($user)
        ->withQueryParams(['from' => $revisions[0], 'to' => $revisions[1]])
        ->test('repository.compare', ['project' => $project])
        ->assertForbidden();
});
