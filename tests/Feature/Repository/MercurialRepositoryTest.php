<?php

use App\Enums\RepositoryType;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\MercurialAdapter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function hgRepositoryMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function hgRun(string $path, array $command): void
{
    Process::path($path)->env(['HGRCPATH' => '', 'HGPLAIN' => '1'])->timeout(15)->run(['hg', ...$command])->throw();
}

function hgCommit(string $path, string $message): void
{
    hgRun($path, ['commit', '-q', '-A', '-u', 'Test Committer <test@example.com>', '-m', $message]);
}

/**
 * @param  array<int, string>  $commitMessages
 */
function createTestHgRepo(array $commitMessages, ?string $parent = null): string
{
    $path = ($parent ?? sys_get_temp_dir()).'/hg-test-'.uniqid();
    mkdir($path);
    $GLOBALS['__testGitRepoPaths'][] = $path;

    hgRun($path, ['init', '-q']);

    foreach ($commitMessages as $i => $message) {
        file_put_contents("{$path}/file{$i}.txt", "content {$i}\n");
        hgCommit($path, $message);
    }

    return $path;
}

test('syncing a mercurial repository records a changeset per commit, oldest first, keyed by node hash', function () {
    $project = Project::factory()->create();
    $path = createTestHgRepo(['Initial commit', 'Second commit']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Mercurial, 'path' => $path]);

    expect(app(RepositorySyncService::class)->sync($repository))->toBe(2);

    $changesets = $repository->changesets()->reorder('id')->with('files')->get();
    expect($changesets->pluck('comments')->all())->toBe(['Initial commit', 'Second commit'])
        ->and($changesets->first()->revision)->toMatch('/^[0-9a-f]{40}$/')
        ->and($changesets->first()->committer)->toBe('Test Committer <test@example.com>')
        ->and($changesets->first()->files->pluck('action', 'path')->all())->toBe(['file0.txt' => 'A']);
});

test('syncing a mercurial repository again only fetches newer commits', function () {
    $project = Project::factory()->create();
    $path = createTestHgRepo(['First']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Mercurial, 'path' => $path]);
    app(RepositorySyncService::class)->sync($repository);

    file_put_contents("{$path}/second.txt", "more\n");
    hgCommit($path, 'Second');

    expect(app(RepositorySyncService::class)->sync($repository->fresh()))->toBe(1)
        ->and($repository->changesets()->count())->toBe(2)
        ->and(app(RepositorySyncService::class)->sync($repository->fresh()))->toBe(0);
});

test('a mercurial commit referencing #N links the changeset to that issue', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $path = createTestHgRepo(["Refs #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Mercurial, 'path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('a mercurial rename, modification and removal are recorded with their actions', function () {
    $project = Project::factory()->create();
    $path = createTestHgRepo(['Initial commit', 'Second commit']);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Mercurial, 'path' => $path]);

    hgRun($path, ['mv', '-q', 'file0.txt', 'renamed.txt']);
    file_put_contents("{$path}/file1.txt", "changed\n");
    hgCommit($path, 'Rename and modify');
    hgRun($path, ['rm', '-q', 'file1.txt']);
    hgCommit($path, 'Remove');

    app(RepositorySyncService::class)->sync($repository);

    $rename = $repository->changesets()->where('comments', 'Rename and modify')->firstOrFail();
    $removal = $repository->changesets()->where('comments', 'Remove')->firstOrFail();

    expect($rename->files->firstWhere('path', 'renamed.txt')->action)->toBe('A')
        ->and($rename->files->firstWhere('path', 'renamed.txt')->from_path)->toBe('file0.txt')
        ->and($rename->files->firstWhere('path', 'file0.txt')->action)->toBe('D')
        ->and($rename->files->firstWhere('path', 'file1.txt')->action)->toBe('M')
        ->and($removal->files->pluck('action', 'path')->all())->toBe(['file1.txt' => 'D']);
});

test('the repository form accepts a mercurial repository under repositories_root only', function () {
    $project = Project::factory()->create();
    $manager = hgRepositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Mercurial->value)
        ->set('path', createTestHgRepo(['Initial commit']))
        ->call('save')
        ->assertHasErrors(['path']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Mercurial->value)
        ->set('path', createTestHgRepo(['Initial commit'], config('scm.repositories_root')))
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('project_id', $project->id)->value('type'))->toBe(RepositoryType::Mercurial);
});

test('browsing, viewing and annotating work through a mercurial-backed repository', function () {
    $project = Project::factory()->create();
    $user = hgRepositoryMember($project, ['browse_repository']);
    $path = createTestHgRepo(['Initial commit']);
    mkdir("{$path}/src/lib", 0777, true);
    file_put_contents("{$path}/src/lib/deep.txt", "deep\n");
    hgCommit($path, 'Add nested file');
    Repository::factory()->for($project)->create(['type' => RepositoryType::Mercurial, 'path' => $path]);

    $root = Livewire::actingAs($user)->test('repository.browse', ['project' => $project]);
    expect(collect($root->get('entries'))->map(fn ($entry) => [$entry->name, $entry->isDirectory])->all())
        ->toBe([['file0.txt', false], ['src', true]]);

    $nested = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'path' => 'src']);
    expect(collect($nested->get('entries'))->pluck('path')->all())->toBe(['src/lib']);

    $entry = Livewire::actingAs($user)->test('repository.entry', ['project' => $project, 'path' => 'src/lib/deep.txt']);
    expect($entry->get('content'))->toBe("deep\n");

    $lines = Livewire::actingAs($user)->test('repository.annotate', ['project' => $project, 'path' => 'file0.txt'])->get('lines');
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->content)->toBe('content 0')
        ->and($lines[0]->revision)->toMatch('/^[0-9a-f]{40}$/')
        ->and($lines[0]->author)->toBe('Test Committer <test@example.com>');
});

test('the mercurial adapter produces single, range and path-scoped diffs', function () {
    $path = createTestHgRepo(['First commit', 'Second commit']);
    file_put_contents("{$path}/file0.txt", "content 0\nmodified\n");
    file_put_contents("{$path}/other.txt", "other\n");
    hgCommit($path, 'Touch two files');

    $adapter = new MercurialAdapter($path);
    $revisions = collect($adapter->log())->pluck('revision')->all();

    expect($adapter->diff($revisions[1]))->toContain('+content 1')->not->toContain('file0.txt')
        ->and($adapter->diff($revisions[2], $revisions[0]))->toContain('+content 1')->toContain('+modified')
        ->and($adapter->diff($revisions[2], path: 'file0.txt'))->toContain('+modified')->not->toContain('other.txt');
});

test('the mercurial adapter ignores hooks and extensions configured in the repository', function () {
    $path = createTestHgRepo(['Initial commit']);
    $marker = sys_get_temp_dir().'/hg-test-hook-'.uniqid();
    $GLOBALS['__testGitRepoPaths'][] = $marker;
    file_put_contents("{$path}/.hg/hgrc", "[hooks]\npre-log = touch {$marker}\npre-cat = touch {$marker}\n");

    $adapter = new MercurialAdapter($path);

    expect($adapter->log())->toHaveCount(1)
        ->and($adapter->fileContentAt('HEAD', 'file0.txt'))->toBe("content 0\n")
        ->and(file_exists($marker))->toBeFalse();
});
