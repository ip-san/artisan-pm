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

function createFileHistoryGitRepoWithRename(): string
{
    $path = config('scm.repositories_root').'/file-history-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    file_put_contents("{$path}/README.md", "first version\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);

    file_put_contents("{$path}/README.md", "second version\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Update README']);

    mkdir("{$path}/docs");
    $run(['git', 'mv', 'README.md', 'docs/README.md']);
    $run(['git', 'commit', '-q', '-m', 'Move README into docs']);

    file_put_contents("{$path}/docs/README.md", "third version\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Update README after move']);

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
        ->pluck('changeset.comments');

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

test('the file history page links to a diff scoped to that file', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->where('comments', 'Update README')->firstOrFail();

    Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'README.md'])
        ->assertSeeHtml(route('repository.show', [$project, $changeset, 'path' => 'README.md']));
});

test('repository.show scopes the diff to a single file when a path query param is set', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project, ['browse_repository', 'view_changesets']);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $initialCommit = $repository->changesets()->where('comments', 'Initial commit')->firstOrFail();

    $scoped = Livewire::actingAs($user)
        ->withQueryParams(['path' => 'README.md'])
        ->test('repository.show', ['project' => $project, 'changeset' => $initialCommit])
        ->get('diff');

    expect($scoped)->toContain('first version')
        ->not->toContain('unrelated');
});

test('repository.show shows the full commit diff when no path is set', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project, ['browse_repository', 'view_changesets']);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $initialCommit = $repository->changesets()->where('comments', 'Initial commit')->firstOrFail();

    $full = Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $initialCommit])
        ->get('diff');

    expect($full)->toContain('first version')
        ->toContain('unrelated');
});

test('the diff pane shows a link back to the full commit diff when scoped to a file', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project, ['browse_repository', 'view_changesets']);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $initialCommit = $repository->changesets()->where('comments', 'Initial commit')->firstOrFail();

    Livewire::actingAs($user)
        ->withQueryParams(['path' => 'README.md'])
        ->test('repository.show', ['project' => $project, 'changeset' => $initialCommit])
        ->assertSee('全体の差分を見る')
        ->assertSeeHtml(route('repository.show', [$project, $initialCommit]));
});

test('the diff pane has no link back to the full diff when not scoped to a file', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project, ['browse_repository', 'view_changesets']);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $initialCommit = $repository->changesets()->where('comments', 'Initial commit')->firstOrFail();

    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $initialCommit])
        ->assertDontSee('全体の差分を見る');
});

test('file history for the new path after a rename includes the pre-rename changesets too', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepoWithRename()]);
    app(RepositorySyncService::class)->sync($repository);

    $comments = Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'docs/README.md'])
        ->get('changesets')
        ->pluck('changeset.comments');

    expect($comments)->toContain('Initial commit', 'Update README', 'Move README into docs', 'Update README after move');
});

test('file history for the old path only shows changesets up to the rename', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepoWithRename()]);
    app(RepositorySyncService::class)->sync($repository);

    $comments = Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'README.md'])
        ->get('changesets')
        ->pluck('changeset.comments');

    expect($comments)->toContain('Initial commit', 'Update README')
        ->not->toContain('Move README into docs', 'Update README after move');
});

test('each entry in a renamed file\'s history links to a diff scoped to its path at that point', function () {
    $project = Project::factory()->create();
    $user = fileHistoryMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createFileHistoryGitRepoWithRename()]);
    app(RepositorySyncService::class)->sync($repository);

    $matches = Livewire::actingAs($user)
        ->test('repository.file-history', ['project' => $project, 'path' => 'docs/README.md'])
        ->get('changesets');

    $pathFor = fn (string $comment) => $matches->firstWhere('changeset.comments', $comment)['path'];

    expect($pathFor('Initial commit'))->toBe('README.md')
        ->and($pathFor('Update README after move'))->toBe('docs/README.md');
});
