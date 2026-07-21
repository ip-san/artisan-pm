<?php

use App\Jobs\RepositorySyncJob;
use App\Models\Changeset;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function repositoryMember(Project $project, array $permissions = ['view_changesets']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * @param  array<int, string>  $commitMessages
 */
function createTestGitRepo(array $commitMessages): string
{
    $path = sys_get_temp_dir().'/scm-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    foreach ($commitMessages as $i => $message) {
        file_put_contents("{$path}/file{$i}.txt", "content {$i}\n");
        $run(['git', 'add', '-A']);
        $run(['git', 'commit', '-q', '-m', $message]);
    }

    return $path;
}

afterEach(function () {
    Process::path(sys_get_temp_dir())->run(['find', '.', '-maxdepth', '1', '-name', 'scm-test-*', '-exec', 'rm', '-rf', '{}', ';']);
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'allowed-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('syncing a repository records a changeset per commit, oldest first', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['Initial commit', 'Second commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    $created = app(RepositorySyncService::class)->sync($repository);

    expect($created)->toBe(2)
        ->and($repository->changesets()->count())->toBe(2);

    // Queried directly by id (creation order) rather than through the
    // changesets() relation, which orders by committed_on desc — two
    // commits made moments apart in the test can land within the same
    // git-timestamp second, so committed_on alone isn't a reliable
    // ordering signal here.
    $revisions = Changeset::query()->where('repository_id', $repository->id)->orderBy('id')->pluck('comments')->all();
    expect($revisions)->toBe(['Initial commit', 'Second commit']);
});

test('syncing again only fetches commits after the last synced revision', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['First']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);
    expect($repository->changesets()->count())->toBe(1);

    Process::path($path)->run(['git', 'commit', '--allow-empty', '-q', '-m', 'Second'])->throw();

    $created = app(RepositorySyncService::class)->sync($repository->fresh());

    expect($created)->toBe(1)
        ->and($repository->changesets()->count())->toBe(2);
});

test('a commit message referencing #123 links the changeset to that issue', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('changeset files record their action', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->files)->toHaveCount(1)
        ->and($changeset->files->first()->action)->toBe('A')
        ->and($changeset->files->first()->path)->toBe('file0.txt');
});

test('a member with view_changesets can see the repository log', function () {
    $project = Project::factory()->create();
    $user = repositoryMember($project);
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    app(RepositorySyncService::class)->sync($repository);

    Livewire::actingAs($user)->test('repository.index', ['project' => $project])->assertOk();

    $changeset = $repository->changesets()->firstOrFail();
    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->assertOk()
        ->assertSee($changeset->shortRevision());
});

test('a member without view_changesets is forbidden from the repository', function () {
    $project = Project::factory()->create();
    $user = repositoryMember($project, []);

    Livewire::actingAs($user)->test('repository.index', ['project' => $project])->assertForbidden();
});

test('only a member with manage_repository can configure the repository or trigger a sync', function () {
    $project = Project::factory()->create();
    $viewer = repositoryMember($project);
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);
    $allowedPath = config('scm.repositories_root').'/allowed-'.uniqid();
    mkdir($allowedPath);

    Livewire::actingAs($viewer)->test('repository.form', ['project' => $project])->assertForbidden();

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('path', $allowedPath)
        ->call('save');

    $repository = Repository::where('project_id', $project->id)->firstOrFail();
    expect(realpath($repository->path))->toBe(realpath($allowedPath));

    Livewire::actingAs($viewer)
        ->test('repository.index', ['project' => $project])
        ->call('sync')
        ->assertForbidden();
});

test('a repository path outside the configured repositories root is rejected', function () {
    $project = Project::factory()->create();
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('path', sys_get_temp_dir())
        ->call('save')
        ->assertHasErrors(['path']);

    expect(Repository::where('project_id', $project->id)->exists())->toBeFalse();
});

test('the diff view is cached rather than re-invoking git on every request', function () {
    $project = Project::factory()->create();
    $user = repositoryMember($project);
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    app(RepositorySyncService::class)->sync($repository);
    $changeset = $repository->changesets()->firstOrFail();

    $first = Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->get('diff');

    Process::path($path)->run(['git', 'commit', '--allow-empty', '-q', '-m', 'Unrelated'])->throw();
    Process::fake(['*' => Process::result(output: 'SHOULD NOT BE CALLED')]);

    $second = Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->get('diff');

    expect($second)->toBe($first)->not->toContain('SHOULD NOT BE CALLED');
});

test('RepositorySyncJob runs the sync service against the given repository', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['Queued sync test']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    RepositorySyncJob::dispatch($repository);

    expect($repository->changesets()->count())->toBe(1);
});
