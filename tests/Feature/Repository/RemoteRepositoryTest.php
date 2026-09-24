<?php

use App\Enums\ProjectModuleKey;
use App\Enums\RepositoryType;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\SvnAdapter;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function remoteRepositoryMember(Project $project, array $permissions = ['view_changesets', 'manage_repository', 'manage_remote_repositories']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * Serves $repositoryPath over svn:// on 127.0.0.1 with one read-only
 * account, so the remote code path runs against a real svnserve.
 *
 * @return array{0: string, 1: InvokedProcess}
 */
function startSvnServe(string $repositoryPath, string $login, string $password): array
{
    file_put_contents("{$repositoryPath}/conf/svnserve.conf", "[general]\nanon-access = none\nauth-access = read\npassword-db = passwd\nrealm = test\n");
    file_put_contents("{$repositoryPath}/conf/passwd", "[users]\n{$login} = {$password}\n");

    $port = random_int(20000, 40000);
    $server = Process::start(['svnserve', '--foreground', '-d', '--listen-host', '127.0.0.1', '--listen-port', (string) $port, '-r', dirname($repositoryPath)]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if ($socket !== false) {
            fclose($socket);

            break;
        }

        usleep(100_000);
    }

    return ["svn://127.0.0.1:{$port}/".basename($repositoryPath), $server];
}

test('manage_remote_repositories is a grantable repository permission that no default role holds', function () {
    $project = Project::factory()->create();
    $user = remoteRepositoryMember($project, ['view_changesets', 'manage_repository']);
    $admin = User::factory()->create(['is_admin' => true]);

    expect($user->can('manageRemote', [Repository::class, $project]))->toBeFalse()
        ->and($admin->can('manageRemote', [Repository::class, $project]))->toBeTrue()
        ->and(remoteRepositoryMember($project)->can('manageRemote', [Repository::class, $project]))->toBeTrue();
});

test('a member with only manage_repository cannot register a remote URL', function () {
    config()->set('scm.allowed_hosts', ['svn.example.com']);
    Process::fake();
    $project = Project::factory()->create();
    $user = remoteRepositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->assertDontSee(__('リモートリポジトリ(Subversionのみ)'))
        ->set('type', 'svn')
        ->set('url', 'https://svn.example.com/repo')
        ->call('save')
        ->assertForbidden();

    expect(Repository::where('project_id', $project->id)->exists())->toBeFalse();
    Process::assertNothingRan();
});

test('a URL is refused without contacting it', function (array $allowedHosts, string $url) {
    config()->set('scm.allowed_hosts', $allowedHosts);
    Process::fake();
    $project = Project::factory()->create();

    Livewire::actingAs(remoteRepositoryMember($project))
        ->test('repository.form', ['project' => $project])
        ->set('type', 'svn')
        ->set('url', $url)
        ->call('save')
        ->assertHasErrors(['url']);

    expect(Repository::where('project_id', $project->id)->exists())->toBeFalse();
    Process::assertNothingRan();
})->with([
    'remote disabled (empty allow-list)' => [[], 'https://svn.example.com/repo'],
    'host not allow-listed' => [['svn.example.com'], 'https://evil.example.net/repo'],
    'file:// scheme' => [['svn.example.com'], 'file:///etc'],
    'svn+ssh:// scheme' => [['svn.example.com'], 'svn+ssh://svn.example.com/repo'],
    'credentials in the URL' => [['svn.example.com'], 'https://user:pass@svn.example.com/repo'],
    'allow-listed name resolving to loopback' => [['localhost'], 'svn://localhost/repo'],
    'metadata address not listed' => [['svn.example.com'], 'http://169.254.169.254/latest'],
    'private address only listed by another host name' => [['svn.example.com', '10.0.0.0/8'], 'http://127.0.0.1/repo'],
]);

test('a remote URL is refused for a git repository', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    Process::fake();
    $project = Project::factory()->create();

    Livewire::actingAs(remoteRepositoryMember($project))
        ->test('repository.form', ['project' => $project])
        ->set('type', 'git')
        ->set('url', 'svn://127.0.0.1/repo')
        ->call('save')
        ->assertHasErrors(['url']);

    Process::assertNothingRan();
});

test('an allow-listed private address is accepted and the credentials are stored encrypted', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    Process::fake();
    $project = Project::factory()->create();

    Livewire::actingAs(remoteRepositoryMember($project))
        ->test('repository.form', ['project' => $project])
        ->assertSee(__('リモートリポジトリ(Subversionのみ)'))
        ->set('type', 'svn')
        ->set('url', 'svn://127.0.0.1/repo')
        ->set('login', 'svnuser')
        ->set('password', 'top-secret-pw')
        ->call('save')
        ->assertHasNoErrors();

    $repository = Repository::where('project_id', $project->id)->firstOrFail();
    $rawPassword = DB::table('repositories')->where('id', $repository->id)->value('password');

    expect($repository->url)->toBe('svn://127.0.0.1/repo')
        ->and($repository->path)->toBeNull()
        ->and($repository->login)->toBe('svnuser')
        ->and($repository->password)->toBe('top-secret-pw')
        ->and($rawPassword)->not->toContain('top-secret-pw');
});

test('the password reaches svn over stdin, never in its arguments', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    Process::fake();

    (new SvnAdapter(url: 'svn://127.0.0.1/repo', login: 'svnuser', password: 'top-secret-pw'))->isAvailable();

    Process::assertRan(function (PendingProcess $process) {
        $arguments = implode(' ', (array) $process->command);

        return ! str_contains($arguments, 'top-secret-pw')
            && $process->input === 'top-secret-pw'
            && in_array('--password-from-stdin', (array) $process->command, true)
            && in_array('--no-auth-cache', (array) $process->command, true)
            && in_array('--non-interactive', (array) $process->command, true)
            && $process->timeout !== null;
    });
});

test('the host is checked again at use time, so a later DNS or allow-list change stops svn from running', function () {
    // Saved while the name pointed somewhere acceptable; now "localhost"
    // resolves to loopback, which isn't listed — as after DNS rebinding.
    config()->set('scm.allowed_hosts', ['localhost']);
    Process::fake();
    $repository = Repository::factory()->remote('svn://localhost/repo')->create();

    expect($repository->adapter()->log())->toBe([])
        ->and($repository->adapter()->isAvailable())->toBeFalse();

    Process::assertNothingRan();
});

test('the stored password never appears in the edit form, its snapshot, the model array or the sys API', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    $project = Project::factory()->create();
    $project->syncModules([ProjectModuleKey::Repository]);
    $repository = Repository::factory()->for($project)->remote('svn://127.0.0.1/repo', 'svnuser', 'top-secret-pw')->create();
    $user = remoteRepositoryMember($project);

    $this->actingAs($user)->get(route('repository.edit', $project))
        ->assertOk()
        ->assertSee(__('リモートリポジトリ(Subversionのみ)'))
        ->assertDontSee('top-secret-pw');

    $component = Livewire::actingAs($user)->test('repository.form', ['project' => $project]);
    expect($component->get('password'))->toBe('')
        ->and(json_encode($component->snapshot))->not->toContain('top-secret-pw')
        ->and(json_encode($repository->fresh()->toArray()))->not->toContain('top-secret-pw');

    Setting::set('sys_api_enabled', true);
    Setting::set('sys_api_key', 'secret-key');
    $this->get('/sys/projects?key=secret-key')
        ->assertOk()
        ->assertJsonFragment(['url' => 'svn://127.0.0.1/repo'])
        ->assertDontSee('top-secret-pw')
        ->assertDontSee('svnuser');
});

test('a blank password on edit keeps the stored one, but a changed URL does not inherit it', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1', '127.0.0.2']);
    Process::fake();
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->remote('svn://127.0.0.1/repo', 'svnuser', 'top-secret-pw')->create();
    $user = remoteRepositoryMember($project);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('identifier', 'mirror')
        ->call('save')
        ->assertHasNoErrors();

    expect($repository->fresh()->password)->toBe('top-secret-pw');

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('url', 'svn://127.0.0.2/other')
        ->call('save')
        ->assertHasNoErrors();

    expect($repository->fresh()->url)->toBe('svn://127.0.0.2/other')
        ->and($repository->fresh()->password)->toBeNull();
});

test('a member without manage_remote_repositories can edit a remote repository without touching its location or credentials', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    Process::fake();
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->remote('svn://127.0.0.1/repo', 'svnuser', 'top-secret-pw')->create();
    $user = remoteRepositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('identifier', 'mirror')
        ->set('password', 'attacker-pw')
        ->call('save')
        ->assertHasNoErrors();

    $repository->refresh();
    expect($repository->identifier)->toBe('mirror')
        ->and($repository->url)->toBe('svn://127.0.0.1/repo')
        ->and($repository->password)->toBe('top-secret-pw');

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('url', 'svn://127.0.0.1/elsewhere')
        ->call('save')
        ->assertForbidden();
});

test('a member without manage_remote_repositories cannot change a remote repository\'s type', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    Process::fake();
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->remote('svn://127.0.0.1/repo')->create();
    $user = remoteRepositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', RepositoryType::Git->value)
        ->call('save')
        ->assertHasErrors(['type']);

    expect($repository->fresh()->type)->toBe(RepositoryType::Svn);
    Process::assertNothingRan();
});

test('a remote repository served by svnserve syncs with the right password and is refused with a wrong one', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    $repositoryPath = createTestSvnRepo(['Initial commit', 'Second commit'], 'remote-svn-');
    [$url, $server] = startSvnServe($repositoryPath, 'svnuser', 'top-secret-pw');

    try {
        $project = Project::factory()->create();
        $repository = Repository::factory()->for($project)->remote($url, 'svnuser', 'top-secret-pw')->create();

        expect(app(RepositorySyncService::class)->sync($repository))->toBe(2)
            ->and((new SvnAdapter(url: $url, login: 'svnuser', password: 'wrong'))->isAvailable())->toBeFalse()
            ->and((new SvnAdapter(url: $url))->isAvailable())->toBeFalse();

        $user = remoteRepositoryMember($project, ['browse_repository']);
        $entry = Livewire::actingAs($user)->test('repository.entry', ['project' => $project, 'path' => 'file0.txt']);
        expect($entry->get('content'))->toBe("content 0\n");
    } finally {
        $server->signal(SIGTERM);
    }
});

test('the form registers a remote repository against a real svnserve', function () {
    config()->set('scm.allowed_hosts', ['127.0.0.1']);
    $repositoryPath = createTestSvnRepo(['Initial commit'], 'remote-svn-');
    [$url, $server] = startSvnServe($repositoryPath, 'svnuser', 'top-secret-pw');

    try {
        $project = Project::factory()->create();
        $user = remoteRepositoryMember($project);

        Livewire::actingAs($user)
            ->test('repository.form', ['project' => $project])
            ->set('type', RepositoryType::Svn->value)
            ->set('url', $url)
            ->set('login', 'svnuser')
            ->set('password', 'wrong')
            ->call('save')
            ->assertHasErrors(['url'])
            ->set('password', 'top-secret-pw')
            ->call('save')
            ->assertHasNoErrors();

        expect(Repository::where('project_id', $project->id)->value('url'))->toBe($url);
    } finally {
        $server->signal(SIGTERM);
    }
});
