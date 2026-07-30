<?php

use App\Models\Changeset;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function multiRepoMember(Project $project, array $permissions = ['browse_repository']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function createMultiRepoGitRepo(string $filename, string $contents): string
{
    $path = config('scm.repositories_root').'/multi-repo-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    file_put_contents("{$path}/{$filename}", $contents);
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'multi-repo-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('Repository::routeName appends no suffix for the default repository and .repo for a non-default one', function () {
    $project = Project::factory()->create();
    $default = Repository::factory()->for($project)->create(['is_default' => true]);
    $secondary = Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    expect($default->routeName('repository.browse'))->toBe('repository.browse')
        ->and($secondary->routeName('repository.browse'))->toBe('repository.browse.repo');
});

test('Repository::routeParameters only includes repositoryParam for a non-default repository', function () {
    $project = Project::factory()->create();
    $default = Repository::factory()->for($project)->create(['is_default' => true]);
    $secondary = Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    expect($default->routeParameters())->not->toHaveKey('repositoryParam')
        ->and($secondary->routeParameters())->toHaveKey('repositoryParam', 'secondary');
});

test('the identifier-less route still resolves the default repository, unchanged from single-repository behavior', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    $defaultPath = createMultiRepoGitRepo('default.md', "default repo content\n");
    Repository::factory()->for($project)->create(['path' => $defaultPath, 'is_default' => true]);

    $component = Livewire::actingAs($user)->test('repository.browse', ['project' => $project]);

    expect(collect($component->get('entries'))->pluck('name'))->toContain('default.md');
});

test('the identifier-bearing route resolves the named non-default repository, not the default one', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    $defaultPath = createMultiRepoGitRepo('default.md', "default repo content\n");
    Repository::factory()->for($project)->create(['path' => $defaultPath, 'is_default' => true]);
    $secondaryPath = createMultiRepoGitRepo('secondary.md', "secondary repo content\n");
    Repository::factory()->for($project)->create(['path' => $secondaryPath, 'identifier' => 'secondary']);

    $component = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'repositoryParam' => 'secondary']);

    $names = collect($component->get('entries'))->pluck('name');
    expect($names)->toContain('secondary.md')->not->toContain('default.md');
});

test('an unknown repositoryParam resolves to no repository and 404s, matching the identifier-less no-repository case', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    Repository::factory()->for($project)->create(['is_default' => true]);

    Livewire::actingAs($user)
        ->test('repository.browse', ['project' => $project, 'repositoryParam' => 'does-not-exist'])
        ->assertStatus(404);
});

test('a numeric repositoryParam resolves by id even when the repository also happens to have no identifier', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    Repository::factory()->for($project)->create(['is_default' => true]);
    $secondaryPath = createMultiRepoGitRepo('by-id.md', "resolved by numeric id\n");
    $secondary = Repository::factory()->for($project)->create(['path' => $secondaryPath]);

    $component = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'repositoryParam' => (string) $secondary->id]);

    expect(collect($component->get('entries'))->pluck('name'))->toContain('by-id.md');
});

test('browsing a non-default repository renders breadcrumb and file links that stay on that repository', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    Repository::factory()->for($project)->create(['is_default' => true]);
    $secondaryPath = createMultiRepoGitRepo('notes.md', "hello\n");
    Repository::factory()->for($project)->create(['path' => $secondaryPath, 'identifier' => 'secondary']);

    $component = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'repositoryParam' => 'secondary']);

    $expectedEntryUrl = route('repository.entry.repo', ['project' => $project, 'repositoryParam' => 'secondary', 'path' => 'notes.md']);
    $component->assertSeeHtml(e($expectedEntryUrl));

    // Following the generated link actually loads the same (non-default)
    // repository's file, proving the round trip end to end rather than
    // just asserting the string the view happened to render.
    $entry = Livewire::actingAs($user)->test('repository.entry', ['project' => $project, 'repositoryParam' => 'secondary', 'path' => 'notes.md']);
    expect($entry->get('content'))->toBe("hello\n");
});

// Livewire::test() hands route parameters straight to mount(), bypassing
// the router entirely — it cannot prove a real GET to a .repo URL actually
// resolves through routes/web.php. These four hit the router for real, one
// per distinct pattern shape registered there: the two-or-more-segment
// verb routes (browse.repo), the one-segment catch-all
// (index.repo — the pattern most at risk of being swallowed by a literal
// verb route registered after it), the route whose Volt mount() has NO
// $repositoryParam argument at all (show.repo — resolves the repository
// via the bound $changeset instead, so the extra route segment must be
// tolerated even though nothing reads it), and the plain controller route
// (raw.repo).
test('GET on repository.browse.repo resolves through the actual router', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    $path = createMultiRepoGitRepo('notes.md', "hello\n");
    Repository::factory()->for($project)->create(['is_default' => true]);
    Repository::factory()->for($project)->create(['path' => $path, 'identifier' => 'secondary']);

    $this->actingAs($user)
        ->get(route('repository.browse.repo', ['project' => $project, 'repositoryParam' => 'secondary']))
        ->assertOk()
        ->assertSee('notes.md');
});

test('GET on repository.index.repo resolves through the actual router without being swallowed by a literal-verb route', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project, ['view_changesets']);
    Repository::factory()->for($project)->create(['is_default' => true]);
    Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    $this->actingAs($user)
        ->get(route('repository.index.repo', ['project' => $project, 'repositoryParam' => 'secondary']))
        ->assertOk();
});

test('GET on repository.show.repo resolves through the actual router even though mount() never reads repositoryParam', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project, ['view_changesets']);
    Repository::factory()->for($project)->create(['is_default' => true]);
    $secondary = Repository::factory()->for($project)->create(['identifier' => 'secondary']);
    $changeset = Changeset::factory()->for($secondary)->create();
    Cache::forever("changeset:{$changeset->id}:diff", '');

    $this->actingAs($user)
        ->get(route('repository.show.repo', ['project' => $project, 'repositoryParam' => 'secondary', 'changeset' => $changeset]))
        ->assertOk();
});

test('GET on repository.raw.repo resolves through the actual router', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    $path = createMultiRepoGitRepo('notes.md', "hello raw\n");
    Repository::factory()->for($project)->create(['is_default' => true]);
    Repository::factory()->for($project)->create(['path' => $path, 'identifier' => 'secondary']);

    $this->actingAs($user)
        ->get(route('repository.raw.repo', ['project' => $project, 'repositoryParam' => 'secondary', 'path' => 'notes.md']))
        ->assertOk()
        ->assertContent("hello raw\n");
});

// The one combination nothing else exercises: {repositoryParam} together
// with a multi-segment {path} matched by the '.*' wildcard — the exact
// shape of the binding bug fixed above (advisor review flagged that the
// other raw.repo test only used a flat, single-segment filename).
test('GET on repository.raw.repo resolves a nested path through the actual router', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project);
    $path = config('scm.repositories_root').'/multi-repo-test-'.uniqid();
    mkdir($path.'/src', recursive: true);
    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);
    file_put_contents("{$path}/src/app.php", "<?php\necho 'nested';\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);
    Repository::factory()->for($project)->create(['is_default' => true]);
    Repository::factory()->for($project)->create(['path' => $path, 'identifier' => 'secondary']);

    $this->actingAs($user)
        ->get(route('repository.raw.repo', ['project' => $project, 'repositoryParam' => 'secondary', 'path' => 'src/app.php']))
        ->assertOk()
        ->assertContent("<?php\necho 'nested';\n");
});

test('the repository index page for a non-default repository links its own action buttons through the .repo routes', function () {
    $project = Project::factory()->create();
    $user = multiRepoMember($project, ['view_changesets', 'browse_repository', 'manage_repository']);
    Repository::factory()->for($project)->create(['is_default' => true]);
    Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    $component = Livewire::actingAs($user)->test('repository.index', ['project' => $project, 'repositoryParam' => 'secondary']);

    $component->assertSeeHtml(e(route('repository.browse.repo', ['project' => $project, 'repositoryParam' => 'secondary'])));
    $component->assertSeeHtml(e(route('repository.stats.repo', ['project' => $project, 'repositoryParam' => 'secondary'])));
    $component->assertSeeHtml(e(route('repository.edit.repo', ['project' => $project, 'repositoryParam' => 'secondary'])));
});
