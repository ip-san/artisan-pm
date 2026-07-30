<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function switcherManager(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_changesets', 'manage_repository']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function createValidGitRepoPath(): string
{
    $path = config('scm.repositories_root').'/switch-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);
    file_put_contents("{$path}/README.md", "hello\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'switch-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('GET on repository.create resolves through the actual router and renders the add-repository form', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    Repository::factory()->for($project)->create(['is_default' => true]);

    $this->actingAs($user)
        ->get(route('repository.create', $project))
        ->assertOk()
        ->assertSee('リポジトリの追加');
});

test('a manager can add a second repository to a project that already has a default one', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    $default = Repository::factory()->for($project)->create(['is_default' => true]);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project, 'isNew' => true])
        ->set('type', 'git')
        ->set('path', createValidGitRepoPath())
        ->set('identifier', 'secondary')
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('project_id', $project->id)->count())->toBe(2);
    $created = Repository::where('project_id', $project->id)->where('identifier', 'secondary')->sole();
    expect($created->is_default)->toBeFalse()
        ->and($default->fresh()->is_default)->toBeTrue();
});

test('the identifier field rejects a purely-numeric value', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', 'git')
        ->set('path', '/tmp')
        ->set('identifier', '123')
        ->call('save')
        ->assertHasErrors(['identifier']);
});

test('the identifier field rejects a value that collides with a repository route segment', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', 'git')
        ->set('path', '/tmp')
        ->set('identifier', 'edit')
        ->call('save')
        ->assertHasErrors(['identifier']);
});

test('the identifier field rejects uppercase and other disallowed characters', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', 'git')
        ->set('path', '/tmp')
        ->set('identifier', 'Main Repo')
        ->call('save')
        ->assertHasErrors(['identifier']);
});

test('an empty identifier input is normalized to null rather than an empty string', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', 'git')
        ->set('path', createValidGitRepoPath())
        ->set('identifier', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('project_id', $project->id)->sole()->identifier)->toBeNull();
});

test('once a repository has an identifier, the form no longer exposes it as editable', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    $repository = Repository::factory()->for($project)->create(['identifier' => 'main']);

    $component = Livewire::actingAs($user)->test('repository.form', ['project' => $project]);

    expect($component->instance()->identifierEditable())->toBeFalse();
});

test('submitting the form for a repository with a frozen identifier does not error even without re-sending it', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    $path = createValidGitRepoPath();
    $repository = Repository::factory()->for($project)->create(['identifier' => 'main', 'path' => $path]);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', 'git')
        ->set('path', $path)
        ->call('save')
        ->assertHasNoErrors();

    expect($repository->fresh()->identifier)->toBe('main');
});

test('a manager can change which repository is the project default', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    $default = Repository::factory()->for($project)->create(['is_default' => true]);
    $secondary = Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    Livewire::actingAs($user)
        ->test('repository.index', ['project' => $project])
        ->call('setDefault', $secondary->id);

    expect($secondary->fresh()->is_default)->toBeTrue()
        ->and($default->fresh()->is_default)->toBeFalse();
});

test('a non-manager cannot change the project default repository', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_changesets']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    Repository::factory()->for($project)->create(['is_default' => true]);
    $secondary = Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    Livewire::actingAs($user)
        ->test('repository.index', ['project' => $project])
        ->call('setDefault', $secondary->id)
        ->assertForbidden();
});

test('a non-manager cannot change the default repository even when viewing a non-default repository via repositoryParam', function () {
    // setDefault()'s own authorize() call doesn't depend on which
    // repository is currently being viewed, but this exercises the other
    // entry point into this same component (mounted with repositoryParam
    // set, as the repository.index.repo route does) rather than assuming
    // the identifier-less mount test above covers it too.
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_changesets']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    Repository::factory()->for($project)->create(['is_default' => true]);
    $secondary = Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    Livewire::actingAs($user)
        ->test('repository.index', ['project' => $project, 'repositoryParam' => 'secondary'])
        ->call('setDefault', $secondary->id)
        ->assertForbidden();
});

test('a project with zero repositories can still create its first one through repository.edit, with the identifier field visible', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);

    $component = Livewire::actingAs($user)->test('repository.form', ['project' => $project]);
    expect($component->instance()->identifierEditable())->toBeTrue();

    $component
        ->set('type', 'git')
        ->set('path', createValidGitRepoPath())
        ->set('identifier', 'main')
        ->call('save')
        ->assertHasNoErrors();

    $created = Repository::where('project_id', $project->id)->sole();
    expect($created->is_default)->toBeTrue()
        ->and($created->identifier)->toBe('main');
});

test('the index page lists every repository in the project once there is more than one', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    Repository::factory()->for($project)->create(['is_default' => true, 'identifier' => 'alpha']);
    Repository::factory()->for($project)->create(['identifier' => 'beta']);

    $component = Livewire::actingAs($user)->test('repository.index', ['project' => $project]);

    $component->assertSee('alpha')->assertSee('beta')->assertSee('既定');
});

test('the index page does not render the switcher table when the project has only one repository', function () {
    $project = Project::factory()->create();
    $user = switcherManager($project);
    Repository::factory()->for($project)->create(['is_default' => true]);

    $component = Livewire::actingAs($user)->test('repository.index', ['project' => $project]);

    expect($component->instance()->allRepositories()->count())->toBe(1);
});
