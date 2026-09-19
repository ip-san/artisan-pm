<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\CodesetConverter;
use App\Support\Scm\GitAdapter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function encodingManager(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_changesets', 'manage_repository']])
    );

    return $user;
}

function encodingGitRepo(string $message): string
{
    $path = config('scm.repositories_root').'/allowed-encoding-'.uniqid();
    mkdir($path);
    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'dev@example.com']);
    $run(['git', 'config', 'user.name', 'Dev']);
    file_put_contents("{$path}/a.txt", 'a');
    $run(['git', 'add', '-A']);
    file_put_contents("{$path}/msg.txt", mb_convert_encoding($message, 'EUC-JP', 'UTF-8'));
    $run(['git', 'config', 'i18n.commitEncoding', 'EUC-JP']);
    $run(['git', 'commit', '-q', '-F', 'msg.txt']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'allowed-encoding-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('the repository form saves per-repository encodings and rejects unknown ones', function () {
    $project = Project::factory()->create();
    $manager = encodingManager($project);
    $path = encodingGitRepo('first');

    Livewire::actingAs($manager)->test('repository.form', ['project' => $project])
        ->set('path', $path)
        ->set('log_encoding', 'EUC-JP')
        ->set('path_encoding', 'SJIS-win')
        ->call('save')
        ->assertHasNoErrors();

    $repository = Repository::query()->where('project_id', $project->id)->firstOrFail();
    expect($repository->only(['log_encoding', 'path_encoding']))->toBe(['log_encoding' => 'EUC-JP', 'path_encoding' => 'SJIS-win']);

    Livewire::actingAs($manager)->test('repository.form', ['project' => $project, 'repositoryParam' => (string) $repository->id])
        ->assertSet('log_encoding', 'EUC-JP')
        ->set('log_encoding', 'GIBBERISH')
        ->call('save')
        ->assertHasErrors(['log_encoding']);

    Livewire::actingAs($manager)->test('repository.form', ['project' => $project, 'repositoryParam' => (string) $repository->id])
        ->set('log_encoding', '')
        ->set('path_encoding', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($repository->fresh()->only(['log_encoding', 'path_encoding']))->toBe(['log_encoding' => null, 'path_encoding' => null]);
});

test('a repository log encoding overrides the global commit_logs_encoding when syncing', function () {
    Setting::set('commit_logs_encoding', 'SJIS-win');
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create([
        'path' => encodingGitRepo('日本語のログ'),
        'log_encoding' => 'EUC-JP',
    ]);

    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->comments)->toBe('日本語のログ');
});

test('the adapter is handed the repository encodings', function () {
    $repository = new Repository(['type' => 'git', 'path' => '/x', 'log_encoding' => 'EUC-JP', 'path_encoding' => 'SJIS-win']);

    expect($repository->adapter())->toBeInstanceOf(GitAdapter::class);
    expect(CodesetConverter::logToUtf8(mb_convert_encoding('日本語', 'EUC-JP', 'UTF-8'), 'EUC-JP'))->toBe('日本語');
});
