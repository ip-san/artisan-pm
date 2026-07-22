<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function statsMember(Project $project, array $permissions = ['view_changesets']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * Three commits: two by Alice in January, one by Bob in February — so
 * both the per-author and per-month breakdowns have something real to
 * group and compare.
 */
function createStatsGitRepo(): string
{
    $path = config('scm.repositories_root').'/stats-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    $commit = function (string $filename, string $content, string $name, string $email, string $date) use ($path) {
        file_put_contents("{$path}/{$filename}", $content);
        Process::path($path)->timeout(10)->run(['git', 'add', '-A'])->throw();
        Process::path($path)->timeout(10)->env([
            'GIT_AUTHOR_NAME' => $name,
            'GIT_AUTHOR_EMAIL' => $email,
            'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_NAME' => $name,
            'GIT_COMMITTER_EMAIL' => $email,
            'GIT_COMMITTER_DATE' => $date,
        ])->run(['git', 'commit', '-q', '-m', "commit by {$name}"])->throw();
    };

    $commit('a.txt', "a\n", 'Alice', 'alice@example.com', '2026-01-15T10:00:00');
    $commit('b.txt', "b\n", 'Alice', 'alice@example.com', '2026-01-20T10:00:00');
    $commit('c.txt', "c\n", 'Bob', 'bob@example.com', '2026-02-10T10:00:00');

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'stats-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('the stats page breaks down commit counts by author and by month', function () {
    $project = Project::factory()->create();
    $user = statsMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createStatsGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $component = Livewire::actingAs($user)->test('repository.stats', ['project' => $project]);

    $byAuthor = $component->get('commitsByAuthor');
    $byMonth = $component->get('commitsByMonth');

    expect($byAuthor->get('Alice <alice@example.com>'))->toBe(2)
        ->and($byAuthor->get('Bob <bob@example.com>'))->toBe(1)
        ->and($byMonth->get('2026-01'))->toBe(2)
        ->and($byMonth->get('2026-02'))->toBe(1);
});

test('authors are sorted by commit count, highest first', function () {
    $project = Project::factory()->create();
    $user = statsMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createStatsGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $byAuthor = Livewire::actingAs($user)
        ->test('repository.stats', ['project' => $project])
        ->get('commitsByAuthor');

    expect($byAuthor->keys()->first())->toBe('Alice <alice@example.com>');
});

test('months are sorted oldest first', function () {
    $project = Project::factory()->create();
    $user = statsMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createStatsGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    $byMonth = Livewire::actingAs($user)
        ->test('repository.stats', ['project' => $project])
        ->get('commitsByMonth');

    expect($byMonth->keys()->all())->toBe(['2026-01', '2026-02']);
});

test('the stats page renders a link from the repository index', function () {
    $project = Project::factory()->create();
    $user = statsMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createStatsGitRepo()]);
    app(RepositorySyncService::class)->sync($repository);

    Livewire::actingAs($user)
        ->test('repository.index', ['project' => $project])
        ->assertSeeHtml(route('repository.stats', $project));
});

test('a member without view_changesets cannot see repository stats', function () {
    $project = Project::factory()->create();
    $user = statsMember($project, []);
    Repository::factory()->for($project)->create();

    Livewire::actingAs($user)->test('repository.stats', ['project' => $project])->assertForbidden();
});

test('a project with no repository configured returns 404 for stats', function () {
    $project = Project::factory()->create();
    $user = statsMember($project);

    Livewire::actingAs($user)->test('repository.stats', ['project' => $project])->assertStatus(404);
});
