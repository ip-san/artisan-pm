<?php

use App\Enums\RepositoryType;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Support\Scm\BazaarAdapter;
use App\Support\Scm\GitAdapter;
use App\Support\Scm\MercurialAdapter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function refsTestRun(string $path, array $command, array $env = []): void
{
    Process::path($path)->env($env)->timeout(30)->run($command)->throw();
}

function refsTestDirectory(string $prefix): string
{
    $path = sys_get_temp_dir().'/'.$prefix.'-'.uniqid();
    mkdir($path);
    $GLOBALS['__testGitRepoPaths'][] = $path;

    return $path;
}

/**
 * main: README "main"; branch "feature" adds feature.txt; tag "v1.0" on
 * the first commit.
 */
function refsTestGitRepo(): string
{
    $path = refsTestDirectory('refs-git');
    refsTestRun($path, ['git', 'init', '-q', '-b', 'main']);
    refsTestRun($path, ['git', 'config', 'user.email', 'test@example.com']);
    refsTestRun($path, ['git', 'config', 'user.name', 'Test']);
    file_put_contents("{$path}/README", "main\n");
    refsTestRun($path, ['git', 'add', '-A']);
    refsTestRun($path, ['git', 'commit', '-q', '-m', 'first']);
    refsTestRun($path, ['git', 'tag', 'v1.0']);
    refsTestRun($path, ['git', 'checkout', '-q', '-b', 'feature']);
    file_put_contents("{$path}/feature.txt", "feature work\n");
    refsTestRun($path, ['git', 'add', '-A']);
    refsTestRun($path, ['git', 'commit', '-q', '-m', 'feature']);
    refsTestRun($path, ['git', 'checkout', '-q', 'main']);
    file_put_contents("{$path}/README", "main updated\n");
    refsTestRun($path, ['git', 'commit', '-q', '-am', 'second']);

    return $path;
}

function refsTestViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['browse_repository']]));

    return $user;
}

test('git lists its branches and tags', function () {
    $adapter = new GitAdapter(refsTestGitRepo());

    expect($adapter->branches())->toBe(['feature', 'main'])
        ->and($adapter->tags())->toBe(['v1.0']);
});

test('mercurial lists its named branches and tags without tip', function () {
    $path = refsTestDirectory('refs-hg');
    $env = ['HGRCPATH' => '', 'HGPLAIN' => '1'];
    refsTestRun($path, ['hg', 'init', '-q'], $env);
    file_put_contents("{$path}/a.txt", "a\n");
    refsTestRun($path, ['hg', 'commit', '-q', '-A', '-u', 'Test <t@example.com>', '-m', 'first'], $env);
    refsTestRun($path, ['hg', 'tag', '-u', 'Test <t@example.com>', 'release-1'], $env);
    refsTestRun($path, ['hg', 'branch', '-q', 'stable'], $env);
    file_put_contents("{$path}/b.txt", "b\n");
    refsTestRun($path, ['hg', 'commit', '-q', '-A', '-u', 'Test <t@example.com>', '-m', 'on stable'], $env);

    $adapter = new MercurialAdapter($path);

    expect($adapter->branches())->toBe(['default', 'stable'])
        ->and($adapter->tags())->toBe(['release-1'])
        ->and(collect($adapter->tree('release-1'))->pluck('name')->all())->toBe(['a.txt']);
});

test('bazaar lists its tags and a tag can be browsed', function () {
    $path = refsTestDirectory('refs-bzr');
    $env = ['BRZ_EMAIL' => 'Test <t@example.com>', 'BRZ_LOG' => '/dev/null'];
    refsTestRun($path, ['brz', 'init', '-q'], $env);
    file_put_contents("{$path}/a.txt", "a\n");
    refsTestRun($path, ['brz', 'add', '-q'], $env);
    refsTestRun($path, ['brz', 'commit', '-q', '-m', 'first'], $env);
    refsTestRun($path, ['brz', 'tag', '-q', 'v1'], $env);
    file_put_contents("{$path}/b.txt", "b\n");
    refsTestRun($path, ['brz', 'add', '-q'], $env);
    refsTestRun($path, ['brz', 'commit', '-q', '-m', 'second'], $env);

    $adapter = new BazaarAdapter($path);
    $repository = Repository::factory()->make(['type' => RepositoryType::Bazaar, 'path' => $path]);

    expect($adapter->branches())->toBe([])
        ->and($adapter->tags())->toBe(['v1'])
        ->and($repository->revisionFor('v1'))->toBe('tag:v1')
        ->and(collect($adapter->tree('tag:v1'))->pluck('name')->all())->toBe(['a.txt']);
});

test('the browser switches to a branch or tag and carries it to files and downloads', function () {
    $project = Project::factory()->create();
    $user = refsTestViewer($project);
    Repository::factory()->for($project)->create(['path' => refsTestGitRepo()]);

    $page = Livewire::actingAs($user)->test('repository.browse', ['project' => $project])
        ->assertSeeHtml('data-repository-refs')
        ->assertDontSee('feature.txt');

    $page->set('rev', 'feature')->assertSee('feature.txt')->assertSeeHtml('rev=feature');

    Livewire::withQueryParams(['rev' => 'v1.0'])->actingAs($user)->test('repository.entry', ['project' => $project, 'path' => 'README'])
        ->assertSee('main')
        ->assertDontSee('main updated');

    $this->actingAs($user)->get(route('repository.raw', ['project' => $project, 'path' => 'README', 'rev' => 'v1.0']))
        ->assertOk()
        ->assertSee("main\n", false);
});

test('a rev the repository does not list falls back to HEAD', function () {
    $project = Project::factory()->create();
    $user = refsTestViewer($project);
    $repository = Repository::factory()->for($project)->create(['path' => refsTestGitRepo()]);

    expect($repository->revisionFor('HEAD~1'))->toBe('HEAD')
        ->and($repository->revisionFor('--output=/tmp/x'))->toBe('HEAD')
        ->and($repository->revisionFor('feature'))->toBe('feature');

    Livewire::withQueryParams(['rev' => 'HEAD~1'])->actingAs($user)->test('repository.entry', ['project' => $project, 'path' => 'README'])
        ->assertSee('main updated');
});
