<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function browseMember(Project $project, array $permissions = ['browse_repository']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function createBrowsableGitRepo(): string
{
    $path = config('scm.repositories_root').'/browse-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    mkdir("{$path}/src");
    file_put_contents("{$path}/README.md", "hello world\n");
    file_put_contents("{$path}/src/app.php", "<?php\necho 'hi';\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'browse-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('a member with browse_repository can list the root tree and a subdirectory', function () {
    $project = Project::factory()->create();
    $user = browseMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createBrowsableGitRepo()]);

    $root = Livewire::actingAs($user)->test('repository.browse', ['project' => $project]);
    $names = collect($root->get('entries'))->pluck('name');
    expect($names)->toContain('README.md')->toContain('src');

    $sub = Livewire::actingAs($user)->test('repository.browse', ['project' => $project, 'path' => 'src']);
    expect(collect($sub->get('entries'))->pluck('name'))->toContain('app.php');
});

test('a member without browse_repository is forbidden from browsing', function () {
    $project = Project::factory()->create();
    $user = browseMember($project, []);
    Repository::factory()->for($project)->create(['path' => createBrowsableGitRepo()]);

    Livewire::actingAs($user)->test('repository.browse', ['project' => $project])->assertForbidden();
});

test('viewing a file entry shows its content at HEAD', function () {
    $project = Project::factory()->create();
    $user = browseMember($project);
    $repository = Repository::factory()->for($project)->create(['path' => createBrowsableGitRepo()]);

    $component = Livewire::actingAs($user)
        ->test('repository.entry', ['project' => $project, 'path' => 'src/app.php']);

    expect($component->get('content'))->toBe("<?php\necho 'hi';\n")
        ->and($component->get('isBinary'))->toBeFalse();
});

test('a binary file is not rendered as text', function () {
    $project = Project::factory()->create();
    $user = browseMember($project);
    $path = createBrowsableGitRepo();
    file_put_contents("{$path}/image.bin", "\xFF\xFE\x00\xFF binary content");
    Process::path($path)->run(['git', 'add', '-A'])->throw();
    Process::path($path)->run(['git', 'commit', '-q', '-m', 'Add binary'])->throw();
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    $component = Livewire::actingAs($user)
        ->test('repository.entry', ['project' => $project, 'path' => 'image.bin']);

    expect($component->get('isBinary'))->toBeTrue();
});
