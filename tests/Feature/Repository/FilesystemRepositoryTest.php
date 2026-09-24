<?php

use App\Enums\RepositoryType;
use App\Enums\ScmCapability;
use App\Jobs\RepositorySyncJob;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Scm\FilesystemAdapter;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function filesystemRepositoryMember(Project $project, array $permissions = ['view_changesets', 'browse_repository', 'manage_repository']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * A plain directory under repositories_root with a file, a subdirectory, a
 * symlink inside the directory and one pointing outside it.
 */
function createFilesystemRepo(): string
{
    $path = config('scm.repositories_root').'/fs-test-'.uniqid();
    $outside = sys_get_temp_dir().'/fs-test-outside-'.uniqid();
    $GLOBALS['__testGitRepoPaths'][] = $path;
    $GLOBALS['__testGitRepoPaths'][] = $outside;

    mkdir("{$path}/docs", 0777, true);
    mkdir($outside);
    file_put_contents("{$path}/README.txt", "hello filesystem\n");
    file_put_contents("{$path}/docs/guide.txt", "guide\n");
    file_put_contents("{$outside}/secret.txt", "outside secret\n");
    symlink("{$path}/README.txt", "{$path}/inside-link.txt");
    symlink("{$outside}/secret.txt", "{$path}/escape-link.txt");
    symlink($outside, "{$path}/escape-dir");

    return $path;
}

test('the filesystem adapter lists entries and reads files, without any history', function () {
    $adapter = new FilesystemAdapter(createFilesystemRepo());

    expect($adapter->isAvailable())->toBeTrue()
        ->and(collect($adapter->tree('HEAD'))->map(fn ($entry) => [$entry->name, $entry->isDirectory])->all())
        ->toBe([['docs', true], ['README.txt', false], ['inside-link.txt', false]])
        ->and(collect($adapter->tree('HEAD', 'docs'))->pluck('path')->all())->toBe(['docs/guide.txt'])
        ->and($adapter->fileContentAt('HEAD', 'docs/guide.txt'))->toBe("guide\n")
        ->and($adapter->fileContentAt('HEAD', 'inside-link.txt'))->toBe("hello filesystem\n")
        ->and($adapter->log())->toBe([])
        ->and($adapter->diff('HEAD'))->toBe('')
        ->and($adapter->blame('HEAD', 'README.txt'))->toBe([]);

    foreach (ScmCapability::cases() as $capability) {
        expect($adapter->supports($capability))->toBeFalse();
    }
});

test('the filesystem adapter refuses paths that leave the repository directory', function (string $path) {
    $adapter = new FilesystemAdapter(createFilesystemRepo());

    expect($adapter->fileContentAt('HEAD', $path))->toBe('')
        ->and($adapter->tree('HEAD', $path))->toBe([]);
})->with([
    'symlink to an outside file' => 'escape-link.txt',
    'symlink to an outside directory' => 'escape-dir',
    'file through an outside directory symlink' => 'escape-dir/secret.txt',
    'parent traversal' => '../../../../etc/passwd',
    'traversal back into the repository' => 'docs/../README.txt',
    'absolute path' => '/etc/passwd',
    'null byte' => "README.txt\0",
]);

test('git and svn adapters support every capability', function () {
    $git = Repository::factory()->make(['type' => RepositoryType::Git]);
    $svn = Repository::factory()->make(['type' => RepositoryType::Svn]);

    foreach (ScmCapability::cases() as $capability) {
        expect($git->supports($capability))->toBeTrue()
            ->and($svn->supports($capability))->toBeTrue();
    }
});

test('filesystem is left out of the default enabled SCM types, as in Redmine', function () {
    $project = Project::factory()->create();

    $types = Livewire::actingAs(filesystemRepositoryMember($project))
        ->test('repository.form', ['project' => $project])
        ->get('enabledTypes')
        ->pluck('value')
        ->all();

    expect($types)->toBe(['git', 'svn', 'mercurial', 'bazaar', 'cvs']);
});

test('the form registers a filesystem repository only inside repositories_root', function () {
    Setting::set('enabled_scm_types', ['git', 'filesystem']);
    $project = Project::factory()->create();
    $user = filesystemRepositoryMember($project);
    $outside = sys_get_temp_dir().'/fs-test-form-'.uniqid();
    mkdir($outside);
    $GLOBALS['__testGitRepoPaths'][] = $outside;

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Filesystem->value)
        ->set('path', $outside)
        ->call('save')
        ->assertHasErrors(['path']);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Filesystem->value)
        ->set('path', createFilesystemRepo())
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('project_id', $project->id)->value('type'))->toBe(RepositoryType::Filesystem);
});

test('a filesystem repository is browsable while history, diff and annotate pages are hidden', function () {
    $project = Project::factory()->create();
    $user = filesystemRepositoryMember($project);
    Repository::factory()->for($project)->create(['type' => RepositoryType::Filesystem, 'path' => createFilesystemRepo()]);

    $this->actingAs($user)->get(route('repository.index', $project))
        ->assertOk()
        ->assertSee(__('ファイル一覧'))
        ->assertSee('repository-no-history', false)
        ->assertDontSee(__('統計'))
        ->assertDontSee(__('コミッター設定'))
        ->assertDontSee(__('選択したリビジョンを比較'))
        ->assertDontSee('wire:click="sync"', false);

    $this->actingAs($user)->get(route('repository.browse', $project))
        ->assertOk()
        ->assertSee('README.txt')
        ->assertDontSee('escape-link.txt')
        ->assertDontSee(route('repository.file-history', ['project' => $project, 'path' => 'README.txt']));

    $this->actingAs($user)->get(route('repository.entry', ['project' => $project, 'path' => 'README.txt']))
        ->assertOk()
        ->assertSee('hello filesystem')
        ->assertDontSee(route('repository.annotate', ['project' => $project, 'path' => 'README.txt']))
        ->assertDontSee(route('repository.file-history', ['project' => $project, 'path' => 'README.txt']))
        ->assertSee(route('repository.raw', ['project' => $project, 'path' => 'README.txt']));

    $this->actingAs($user)->get(route('repository.raw', ['project' => $project, 'path' => 'escape-link.txt']))
        ->assertOk()
        ->assertDontSee('outside secret');
});

test('the history, diff and annotate routes answer 404 for a filesystem repository', function (string $route, array $parameters) {
    $project = Project::factory()->create();
    $user = filesystemRepositoryMember($project);
    Repository::factory()->for($project)->create(['type' => RepositoryType::Filesystem, 'path' => createFilesystemRepo()]);

    $this->actingAs($user)->get(route($route, ['project' => $project, ...$parameters]))->assertNotFound();
})->with([
    'stats' => ['repository.stats', []],
    'committers' => ['repository.committers', []],
    'compare' => ['repository.compare', ['from' => '1', 'to' => '2']],
    'annotate' => ['repository.annotate', ['path' => 'README.txt']],
    'file history' => ['repository.file-history', ['path' => 'README.txt']],
]);

test('syncing a filesystem repository is refused', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $user = filesystemRepositoryMember($project);
    Repository::factory()->for($project)->create(['type' => RepositoryType::Filesystem, 'path' => createFilesystemRepo()]);

    Livewire::actingAs($user)
        ->test('repository.index', ['project' => $project])
        ->call('sync')
        ->assertNotFound();

    Queue::assertNotPushed(RepositorySyncJob::class);
});

test('with path_encoding, names stored in that encoding are listed and read as UTF-8', function () {
    // The container's own /tmp: the bind-mounted project may be on a filesystem
    // that only accepts UTF-8 names.
    $path = sys_get_temp_dir().'/fs-sjis-'.uniqid();
    $GLOBALS['__testGitRepoPaths'][] = $path;
    $directory = mb_convert_encoding('資料', 'SJIS-win', 'UTF-8');
    $file = mb_convert_encoding('仕様書.txt', 'SJIS-win', 'UTF-8');
    mkdir("{$path}/{$directory}", 0777, true);
    file_put_contents("{$path}/{$directory}/{$file}", "spec\n");

    $adapter = new FilesystemAdapter($path, 'SJIS-win');

    expect(collect($adapter->tree('HEAD'))->pluck('name')->all())->toBe(['資料'])
        ->and(collect($adapter->tree('HEAD', '資料'))->pluck('path')->all())->toBe(['資料/仕様書.txt'])
        ->and($adapter->fileContentAt('HEAD', '資料/仕様書.txt'))->toBe("spec\n")
        ->and(Repository::factory()->make(['type' => RepositoryType::Filesystem, 'path' => $path, 'path_encoding' => 'SJIS-win'])->adapter()->fileContentAt('HEAD', '資料/仕様書.txt'))->toBe("spec\n");
});
