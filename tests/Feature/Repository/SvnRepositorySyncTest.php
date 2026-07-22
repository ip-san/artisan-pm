<?php

use App\Enums\RepositoryType;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\SvnAdapter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function svnRepositoryMember(Project $project, array $permissions): User
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
function createTestSvnRepo(array $commitMessages): string
{
    $repoPath = sys_get_temp_dir().'/svn-test-repo-'.uniqid();
    $wcPath = sys_get_temp_dir().'/svn-test-wc-'.uniqid();

    $run = fn (array $command, ?string $cwd = null) => Process::path($cwd ?? sys_get_temp_dir())->timeout(15)->run($command)->throw();

    $run(['svnadmin', 'create', $repoPath]);
    $run(['svn', 'checkout', "file://{$repoPath}", $wcPath, '-q']);

    foreach ($commitMessages as $i => $message) {
        file_put_contents("{$wcPath}/file{$i}.txt", "content {$i}\n");
        $run(['svn', 'add', "file{$i}.txt"], $wcPath);
        $run(['svn', 'commit', '-m', $message, '-q', '--username', 'tester'], $wcPath);
    }

    return $repoPath;
}

afterEach(function () {
    Process::path(sys_get_temp_dir())->run(['find', '.', '-maxdepth', '1', '-name', 'svn-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('syncing an svn repository records a changeset per revision, oldest first', function () {
    $project = Project::factory()->create();
    $path = createTestSvnRepo(['Initial commit', 'Second commit']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Svn, 'path' => $path]);

    $created = app(RepositorySyncService::class)->sync($repository);

    expect($created)->toBe(2);

    // reorder(), not orderBy(): the changesets() relation bakes in
    // orderByDesc('committed_on'), which a plain orderBy would only
    // append a tiebreaker to — flipping the result whenever the two
    // commits land in different seconds.
    $revisions = $repository->changesets()->reorder('id')->pluck('revision', 'comments')->all();
    expect($revisions)->toBe(['Initial commit' => '1', 'Second commit' => '2']);
});

test('syncing an svn repository again only fetches revisions after the last synced one', function () {
    $project = Project::factory()->create();
    $path = createTestSvnRepo(['First']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Svn, 'path' => $path]);

    app(RepositorySyncService::class)->sync($repository);
    expect($repository->changesets()->count())->toBe(1);

    $wcPath = sys_get_temp_dir().'/svn-test-wc2-'.uniqid();
    Process::path(sys_get_temp_dir())->run(['svn', 'checkout', "file://{$path}", $wcPath, '-q'])->throw();
    file_put_contents("{$wcPath}/second.txt", "more\n");
    Process::path($wcPath)->run(['svn', 'add', 'second.txt'])->throw();
    Process::path($wcPath)->run(['svn', 'commit', '-m', 'Second', '-q', '--username', 'tester'])->throw();

    $created = app(RepositorySyncService::class)->sync($repository->fresh());

    expect($created)->toBe(1)
        ->and($repository->changesets()->count())->toBe(2);
});

test('an svn commit message referencing #123 links the changeset to that issue', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $path = createTestSvnRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Svn, 'path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('svn changeset files record their action', function () {
    $project = Project::factory()->create();
    $path = createTestSvnRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Svn, 'path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->files)->toHaveCount(1)
        ->and($changeset->files->first()->action)->toBe('A')
        ->and($changeset->files->first()->path)->toBe('file0.txt');
});

test('the repository form accepts svn as a repository type', function () {
    $project = Project::factory()->create();
    $manager = svnRepositoryMember($project, ['view_changesets', 'manage_repository']);
    $allowedPath = config('scm.repositories_root').'/allowed-svn-'.uniqid();
    Process::path(config('scm.repositories_root'))->run(['svnadmin', 'create', $allowedPath])->throw();

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Svn->value)
        ->set('path', $allowedPath)
        ->call('save');

    $repository = Repository::where('project_id', $project->id)->firstOrFail();
    expect($repository->type)->toBe(RepositoryType::Svn);

    Process::path(config('scm.repositories_root'))->run(['rm', '-rf', $allowedPath]);
});

test('browsing and viewing a file work through an svn-backed repository', function () {
    $project = Project::factory()->create();
    $user = svnRepositoryMember($project, ['browse_repository']);
    $path = createTestSvnRepo(['Initial commit']);
    Repository::factory()->for($project)->create(['type' => RepositoryType::Svn, 'path' => $path]);

    $listing = Livewire::actingAs($user)->test('repository.browse', ['project' => $project]);
    expect(collect($listing->get('entries'))->pluck('name'))->toContain('file0.txt');

    $entry = Livewire::actingAs($user)
        ->test('repository.entry', ['project' => $project, 'path' => 'file0.txt']);
    expect($entry->get('content'))->toBe("content 0\n");
});

test('annotating a file through an svn-backed repository shows a revision and author per line', function () {
    $project = Project::factory()->create();
    $user = svnRepositoryMember($project, ['browse_repository']);
    $path = createTestSvnRepo(['Initial commit']);
    Repository::factory()->for($project)->create(['type' => RepositoryType::Svn, 'path' => $path]);

    $component = Livewire::actingAs($user)
        ->test('repository.annotate', ['project' => $project, 'path' => 'file0.txt']);

    $lines = $component->get('lines');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->content)->toBe('content 0')
        ->and($lines[0]->revision)->toBe('1')
        ->and($lines[0]->author)->toBe('tester');
});

test('the svn adapter produces a range diff between two revisions', function () {
    $path = createTestSvnRepo(['First commit', 'Second commit']);

    $diff = (new SvnAdapter($path))->diff('2', '1');

    expect($diff)->toContain('file1.txt')
        ->toContain('+content 1')
        ->not->toContain('file0.txt');
});
