<?php

use App\Enums\RepositoryType;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\CvsAdapter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function cvsRepositoryMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function cvsRun(string $cwd, array $command): void
{
    Process::path($cwd)->timeout(30)->run(['cvs', '-f', '-Q', ...$command])->throw();
}

/**
 * A CVS repository under repositories_root (CvsAdapter looks for the
 * CVSROOT no higher than that) with a module "proj" imported from
 * file0.txt and d/nested.txt, plus a working copy to commit from.
 *
 * @return array{module: string, wc: string, root: string}
 */
function createTestCvsRepo(string $importMessage = 'Import'): array
{
    $root = config('scm.repositories_root').'/cvs-test-'.uniqid();
    $import = sys_get_temp_dir().'/cvs-test-import-'.uniqid();
    $wc = sys_get_temp_dir().'/cvs-test-wc-'.uniqid();
    foreach ([$root, $import, $wc] as $created) {
        $GLOBALS['__testGitRepoPaths'][] = $created;
    }

    mkdir($root);
    mkdir("{$import}/d", 0777, true);
    file_put_contents("{$import}/file0.txt", "content 0\n");
    file_put_contents("{$import}/d/nested.txt", "nested\n");

    cvsRun($root, ['-d', $root, 'init']);
    cvsRun($import, ['-d', $root, 'import', '-m', $importMessage, 'proj', 'vendor', 'start']);
    cvsRun(sys_get_temp_dir(), ['-d', $root, 'checkout', '-d', basename($wc), 'proj']);

    return ['module' => "{$root}/proj", 'wc' => $wc, 'root' => $root];
}

function cvsCurrentUser(): string
{
    return posix_getpwuid(posix_geteuid())['name'];
}

test('syncing a cvs module groups file revisions into numbered changesets, oldest first', function () {
    $project = Project::factory()->create();
    $repo = createTestCvsRepo();
    file_put_contents("{$repo['wc']}/file0.txt", "content 0\nchanged\n");
    file_put_contents("{$repo['wc']}/d/nested.txt", "nested\nchanged\n");
    cvsRun($repo['wc'], ['commit', '-m', "Change two files\n\nwith a body"]);
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Cvs, 'path' => $repo['module']]);

    expect(app(RepositorySyncService::class)->sync($repository))->toBe(2);

    $changesets = $repository->changesets()->reorder('id')->with('files')->get();
    expect($changesets->pluck('comments', 'revision')->all())->toBe(['1' => 'Import', '2' => "Change two files\n\nwith a body"])
        ->and($changesets->first()->committer)->toBe(cvsCurrentUser())
        ->and($changesets->first()->files->pluck('action', 'path')->sortKeys()->all())->toBe(['d/nested.txt' => 'A', 'file0.txt' => 'A'])
        ->and($changesets->last()->files->pluck('action', 'path')->sortKeys()->all())->toBe(['d/nested.txt' => 'M', 'file0.txt' => 'M']);
});

test('syncing a cvs module again only adds the new changesets, with removals and re-adds', function () {
    $project = Project::factory()->create();
    $repo = createTestCvsRepo();
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Cvs, 'path' => $repo['module']]);
    app(RepositorySyncService::class)->sync($repository);

    cvsRun($repo['wc'], ['remove', '-f', 'file0.txt']);
    cvsRun($repo['wc'], ['commit', '-m', 'Remove file0']);
    file_put_contents("{$repo['wc']}/file0.txt", "back\n");
    cvsRun($repo['wc'], ['add', 'file0.txt']);
    cvsRun($repo['wc'], ['commit', '-m', 'Restore file0']);

    expect(app(RepositorySyncService::class)->sync($repository->fresh()))->toBe(2)
        ->and(app(RepositorySyncService::class)->sync($repository->fresh()))->toBe(0);

    $changesets = $repository->changesets()->reorder('id')->with('files')->get();
    expect($changesets->pluck('revision')->all())->toBe(['1', '2', '3'])
        ->and($changesets[1]->files->pluck('action', 'path')->all())->toBe(['file0.txt' => 'D'])
        ->and($changesets[2]->files->pluck('action', 'path')->all())->toBe(['file0.txt' => 'A']);
});

test('a cvs commit referencing #N links the changeset to that issue', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $repo = createTestCvsRepo("Refs #{$issue->id}");
    $repository = Repository::factory()->for($project)->create(['type' => RepositoryType::Cvs, 'path' => $repo['module']]);

    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('the repository form accepts a cvs module under repositories_root only', function () {
    $project = Project::factory()->create();
    $manager = cvsRepositoryMember($project, ['view_changesets', 'manage_repository']);
    $repo = createTestCvsRepo();

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Cvs->value)
        ->set('path', $repo['wc'])
        ->call('save')
        ->assertHasErrors(['path']);

    // The CVSROOT's parent itself names no module.
    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Cvs->value)
        ->set('path', $repo['root'])
        ->call('save')
        ->assertHasErrors(['path']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Cvs->value)
        ->set('path', $repo['module'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('project_id', $project->id)->value('type'))->toBe(RepositoryType::Cvs);
});

test('browsing, viewing and annotating work through a cvs-backed repository', function () {
    $project = Project::factory()->create();
    $user = cvsRepositoryMember($project, ['browse_repository']);
    $repo = createTestCvsRepo();
    file_put_contents("{$repo['wc']}/file0.txt", "content 0\n\nsecond line\n");
    cvsRun($repo['wc'], ['commit', '-m', 'Extend file0']);
    Repository::factory()->for($project)->create(['type' => RepositoryType::Cvs, 'path' => $repo['module']]);

    $root = Livewire::actingAs($user)->test('repository.browse', ['project' => $project]);
    expect(collect($root->get('entries'))->map(fn ($entry) => [$entry->name, $entry->isDirectory])->all())
        ->toBe([['d', true], ['file0.txt', false]]);

    $nested = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'path' => 'd']);
    expect(collect($nested->get('entries'))->pluck('path')->all())->toBe(['d/nested.txt']);

    $entry = Livewire::actingAs($user)->test('repository.entry', ['project' => $project, 'path' => 'd/nested.txt']);
    expect($entry->get('content'))->toBe("nested\n");

    $lines = Livewire::actingAs($user)->test('repository.annotate', ['project' => $project, 'path' => 'file0.txt'])->get('lines');
    expect(collect($lines)->map(fn ($line) => [$line->revision, $line->content])->all())
        ->toBe([['1.1', 'content 0'], ['1.2', ''], ['1.2', 'second line']])
        ->and($lines[0]->author)->toBe(cvsCurrentUser());
});

test('the cvs adapter produces single, range and path-scoped diffs, including added and removed files', function () {
    $repo = createTestCvsRepo();
    file_put_contents("{$repo['wc']}/file0.txt", "content 0\nmodified\n");
    file_put_contents("{$repo['wc']}/added.txt", "brand new\n");
    cvsRun($repo['wc'], ['add', 'added.txt']);
    cvsRun($repo['wc'], ['commit', '-m', 'Modify and add']);
    cvsRun($repo['wc'], ['remove', '-f', 'd/nested.txt']);
    cvsRun($repo['wc'], ['commit', '-m', 'Remove nested']);

    $adapter = new CvsAdapter($repo['module']);

    expect($adapter->diff('2'))->toContain('+modified')->toContain('+brand new')->not->toContain('nested')
        ->and($adapter->diff('3'))->toContain('-nested')
        ->and($adapter->diff('3', '1'))->toContain('+modified')->toContain('+brand new')->toContain('-nested')
        ->and($adapter->diff('2', path: 'file0.txt'))->toContain('+modified')->not->toContain('added.txt')
        ->and($adapter->tree('HEAD'))->toHaveCount(2)
        ->and($adapter->fileContentAt('1', 'd/nested.txt'))->toBe("nested\n")
        ->and($adapter->fileContentAt('HEAD', 'd/nested.txt'))->toBe('');
});

test('a cvs path whose CVSROOT would lie outside repositories_root is not available', function () {
    $root = sys_get_temp_dir().'/cvs-test-outside-'.uniqid();
    $GLOBALS['__testGitRepoPaths'][] = $root;
    mkdir("{$root}/CVSROOT", 0777, true);
    mkdir("{$root}/proj");

    expect((new CvsAdapter("{$root}/proj"))->isAvailable())->toBeFalse();
});
