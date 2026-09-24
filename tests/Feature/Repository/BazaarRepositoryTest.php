<?php

use App\Enums\RepositoryType;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\BazaarAdapter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function bzrRepositoryMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function bzrRun(string $path, array $command): void
{
    Process::path($path)
        ->env(['BRZ_EMAIL' => 'Test Committer <test@example.com>', 'BRZ_LOG' => '/dev/null'])
        ->timeout(30)
        ->run(['brz', ...$command])
        ->throw();
}

function bzrCommit(string $path, string $message): void
{
    bzrRun($path, ['add', '-q']);
    bzrRun($path, ['commit', '-q', '-m', $message]);
}

/**
 * @param  array<int, string>  $commitMessages
 */
function createTestBzrRepo(array $commitMessages, ?string $parent = null): string
{
    $path = ($parent ?? sys_get_temp_dir()).'/bzr-test-'.uniqid();
    mkdir($path);
    $GLOBALS['__testGitRepoPaths'][] = $path;

    bzrRun($path, ['init', '-q']);

    foreach ($commitMessages as $i => $message) {
        file_put_contents("{$path}/file{$i}.txt", "content {$i}\n");
        bzrCommit($path, $message);
    }

    return $path;
}

test('syncing a bazaar branch records a changeset per mainline revision, oldest first', function () {
    $project = Project::factory()->create();
    $path = createTestBzrRepo(['Initial commit', "Second commit\n\nwith a body"]);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Bazaar, 'path' => $path]);

    expect(app(RepositorySyncService::class)->sync($repository))->toBe(2);

    $changesets = $repository->changesets()->reorder('id')->with('files')->get();
    expect($changesets->pluck('comments', 'revision')->all())->toBe(['1' => 'Initial commit', '2' => "Second commit\n\nwith a body"])
        ->and($changesets->first()->committer)->toBe('Test Committer <test@example.com>')
        ->and($changesets->first()->files->pluck('action', 'path')->all())->toBe(['file0.txt' => 'A']);
});

test('syncing a bazaar branch again only fetches newer revisions', function () {
    $project = Project::factory()->create();
    $path = createTestBzrRepo(['First']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Bazaar, 'path' => $path]);
    app(RepositorySyncService::class)->sync($repository);

    file_put_contents("{$path}/second.txt", "more\n");
    bzrCommit($path, 'Second');

    expect(app(RepositorySyncService::class)->sync($repository->fresh()))->toBe(1)
        ->and($repository->changesets()->count())->toBe(2)
        ->and(app(RepositorySyncService::class)->sync($repository->fresh()))->toBe(0);
});

test('a bazaar commit referencing #N links the changeset to that issue', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $path = createTestBzrRepo(["Refs #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Bazaar, 'path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('a bazaar rename, modification and removal are recorded with their actions', function () {
    $project = Project::factory()->create();
    $path = createTestBzrRepo(['Initial commit', 'Second commit']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Bazaar, 'path' => $path]);

    bzrRun($path, ['mv', '-q', 'file0.txt', 'renamed.txt']);
    file_put_contents("{$path}/file1.txt", "changed\n");
    mkdir("{$path}/dir");
    file_put_contents("{$path}/dir/new.txt", "new\n");
    bzrCommit($path, 'Rename, modify and add');
    bzrRun($path, ['rm', '-q', 'file1.txt']);
    bzrCommit($path, 'Remove');

    app(RepositorySyncService::class)->sync($repository);

    $rename = $repository->changesets()->where('comments', 'Rename, modify and add')->firstOrFail();
    $removal = $repository->changesets()->where('comments', 'Remove')->firstOrFail();

    expect($rename->files->pluck('action', 'path')->sortKeys()->all())->toBe(['dir/new.txt' => 'A', 'file1.txt' => 'M', 'renamed.txt' => 'R'])
        ->and($rename->files->firstWhere('path', 'renamed.txt')->from_path)->toBe('file0.txt')
        ->and($removal->files->pluck('action', 'path')->all())->toBe(['file1.txt' => 'D']);
});

test('the repository form accepts a bazaar branch under repositories_root only', function () {
    $project = Project::factory()->create();
    $manager = bzrRepositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Bazaar->value)
        ->set('path', createTestBzrRepo(['Initial commit']))
        ->call('save')
        ->assertHasErrors(['path']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Bazaar->value)
        ->set('path', createTestBzrRepo(['Initial commit'], config('scm.repositories_root')))
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('project_id', $project->id)->value('type'))->toBe(RepositoryType::Bazaar);
});

test('browsing, viewing and annotating work through a bazaar-backed repository', function () {
    $project = Project::factory()->create();
    $user = bzrRepositoryMember($project, ['browse_repository']);
    $path = createTestBzrRepo(['Initial commit']);
    mkdir("{$path}/src/lib", 0777, true);
    file_put_contents("{$path}/src/lib/deep.txt", "deep\n");
    file_put_contents("{$path}/file0.txt", "content 0\n\nsecond line\n");
    bzrCommit($path, 'Add nested file');
    Repository::factory()->for($project)->create(['type' => RepositoryType::Bazaar, 'path' => $path]);

    $root = Livewire::actingAs($user)->test('repository.browse', ['project' => $project]);
    expect(collect($root->get('entries'))->map(fn ($entry) => [$entry->name, $entry->isDirectory])->all())
        ->toBe([['file0.txt', false], ['src', true]]);

    $nested = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'path' => 'src']);
    expect(collect($nested->get('entries'))->pluck('path')->all())->toBe(['src/lib']);

    $entry = Livewire::actingAs($user)->test('repository.entry', ['project' => $project, 'path' => 'src/lib/deep.txt']);
    expect($entry->get('content'))->toBe("deep\n");

    $lines = Livewire::actingAs($user)->test('repository.annotate', ['project' => $project, 'path' => 'file0.txt'])->get('lines');
    expect(collect($lines)->map(fn ($line) => [$line->revision, $line->content])->all())
        ->toBe([['1', 'content 0'], ['2', ''], ['2', 'second line']])
        ->and($lines[0]->author)->toBe('test@example.com');
});

test('the bazaar adapter produces single, range and path-scoped diffs', function () {
    $path = createTestBzrRepo(['First commit', 'Second commit']);
    file_put_contents("{$path}/file0.txt", "content 0\nmodified\n");
    file_put_contents("{$path}/other.txt", "other\n");
    bzrCommit($path, 'Touch two files');

    $adapter = new BazaarAdapter($path);

    expect($adapter->diff('2'))->toContain('+content 1')->not->toContain('file0.txt')
        ->and($adapter->diff('3', '1'))->toContain('+content 1')->toContain('+modified')
        ->and($adapter->diff('3', path: 'file0.txt'))->toContain('+modified')->not->toContain('other.txt')
        ->and($adapter->diff('HEAD', path: 'file1.txt'))->toBe('');
});
