<?php

use App\Exceptions\ScmCommandFailedException;
use App\Jobs\AutofetchRepositoryChangesetsJob;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\Process\Exception\LogicException as SymfonyLogicException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Shared hosting: the VCS binary may be missing, proc_open disabled, or a
 * command may run past its timeout. Repository pages show a message
 * instead of a 500, and autofetch goes on with the next repository.
 */
function commandFailureMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['browse_repository', 'view_changesets', 'manage_repository']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function commandFailureGitRepo(): string
{
    $path = config('scm.repositories_root').'/command-failure-test-'.uniqid();
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

// Not through Process: several tests fake it to fail.
afterEach(function () {
    foreach (glob(config('scm.repositories_root').'/command-failure-test-*') ?: [] as $path) {
        File::deleteDirectory($path);
    }
});

test('a missing VCS binary shows a message on the browse page instead of a 500', function () {
    $project = Project::factory()->create();
    $user = commandFailureMember($project);
    Repository::factory()->for($project)->create(['path' => commandFailureGitRepo()]);

    Process::fake(['*' => Process::result(errorOutput: 'sh: 1: exec: git: not found', exitCode: 127)]);

    $this->actingAs($user)
        ->get(route('repository.browse', ['project' => $project]))
        ->assertStatus(503)
        ->assertSee(ScmCommandFailedException::userMessage());
});

test('disabled proc_open shows a message on the file page and the raw download', function () {
    $project = Project::factory()->create();
    $user = commandFailureMember($project);
    Repository::factory()->for($project)->create(['path' => commandFailureGitRepo()]);

    Process::fake(fn () => throw new SymfonyLogicException('The Process class relies on proc_open, which is not available on your PHP installation.'));

    $this->actingAs($user)
        ->get(route('repository.entry', ['project' => $project, 'path' => 'README.md']))
        ->assertStatus(503)
        ->assertSee(ScmCommandFailedException::userMessage());

    $this->actingAs($user)
        ->get(route('repository.raw', ['project' => $project, 'path' => 'README.md']))
        ->assertStatus(503);
});

test('a command that runs past its timeout shows a message', function () {
    $project = Project::factory()->create();
    $user = commandFailureMember($project);
    Repository::factory()->for($project)->create(['path' => commandFailureGitRepo()]);

    Process::fake(fn () => throw new ProcessTimedOutException(
        new SymfonyTimedOutException(new SymfonyProcess(['git']), SymfonyTimedOutException::TYPE_GENERAL),
        new FakeProcessResult(command: 'git'),
    ));

    $this->actingAs($user)
        ->get(route('repository.browse', ['project' => $project]))
        ->assertStatus(503)
        ->assertSee(ScmCommandFailedException::userMessage());
});

test('a repository whose command cannot run is reported as unavailable, not thrown', function () {
    $missingDirectory = Repository::factory()->make(['path' => sys_get_temp_dir().'/command-failure-missing-'.uniqid()]);
    expect($missingDirectory->adapter()->isAvailable())->toBeFalse();

    Process::fake(['*' => Process::result(exitCode: 127)]);
    $repository = Repository::factory()->make(['path' => sys_get_temp_dir()]);
    expect($repository->adapter()->isAvailable())->toBeFalse();
});

test('the sync button shows the message when the job runs inline and fails', function () {
    $project = Project::factory()->create();
    $user = commandFailureMember($project);
    Repository::factory()->for($project)->create(['path' => commandFailureGitRepo()]);

    Process::fake(['*' => Process::result(exitCode: 127)]);

    Livewire::actingAs($user)
        ->test('repository.index', ['project' => $project])
        ->call('sync')
        ->assertSee(ScmCommandFailedException::userMessage());
});

test('autofetch logs a repository that fails and goes on with the next one', function () {
    Setting::set('autofetch_changesets', true);
    Log::spy();

    $broken = Repository::factory()->for(Project::factory())->create(['path' => sys_get_temp_dir().'/command-failure-missing-'.uniqid()]);
    $working = Repository::factory()->for(Project::factory())->create(['path' => commandFailureGitRepo()]);

    (new AutofetchRepositoryChangesetsJob)->handle();

    expect($working->changesets()->count())->toBe(1)
        ->and($broken->changesets()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'autofetch') && ($context['repository_id'] ?? null) === $broken->id)
        ->once();
});
