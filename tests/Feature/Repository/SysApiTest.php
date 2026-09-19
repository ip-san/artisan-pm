<?php

use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use App\Jobs\RepositorySyncJob;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function sysProject(bool $repositoryModule = true, ProjectStatus $status = ProjectStatus::Active): Project
{
    $project = Project::factory()->create(['status' => $status->value]);
    $project->syncModules($repositoryModule ? [ProjectModuleKey::Repository] : []);

    return $project;
}

function sysEnable(string $key = 'secret-key'): void
{
    Setting::set('sys_api_enabled', true);
    Setting::set('sys_api_key', $key);
}

test('the web service is refused while disabled, without a key or with a wrong one', function () {
    $this->get('/sys/projects?key=anything')->assertForbidden();

    Setting::set('sys_api_enabled', true);
    Setting::set('sys_api_key', '');
    $this->get('/sys/projects')->assertForbidden();
    $this->get('/sys/projects?key=')->assertForbidden();

    sysEnable();
    $this->get('/sys/projects')->assertForbidden();
    $this->get('/sys/projects?key=wrong')->assertForbidden();
    $this->get('/sys/projects?key=secret-key')->assertOk();

    Setting::set('sys_api_enabled', false);
    $this->get('/sys/projects?key=secret-key')->assertForbidden();
});

test('projects lists active projects that have the repository module with their default repository', function () {
    sysEnable();
    $withRepo = sysProject();
    $repository = Repository::factory()->for($withRepo)->create(['path' => '/repos/one.git']);
    $withoutRepo = sysProject();
    $noModule = sysProject(repositoryModule: false);
    $archived = sysProject(status: ProjectStatus::Archived);
    Repository::factory()->for($noModule)->create();
    Repository::factory()->for($archived)->create();

    $rows = collect($this->getJson('/sys/projects?key=secret-key')->assertOk()->json())->keyBy('identifier');

    expect($rows->keys()->all())->toEqualCanonicalizing([$withRepo->identifier, $withoutRepo->identifier])
        ->and($rows[$withRepo->identifier]['repository'])->toBe(['id' => $repository->id, 'url' => '/repos/one.git'])
        ->and($rows[$withoutRepo->identifier]['repository'])->toBeNull()
        ->and($rows[$withRepo->identifier])->toHaveKeys(['id', 'identifier', 'name', 'is_public', 'status']);
});

test('fetch_changesets queues a sync for one project by id or identifier and answers 404 for unknown ones', function () {
    Queue::fake();
    sysEnable();
    $project = sysProject();
    $repository = Repository::factory()->for($project)->create();
    $other = sysProject();
    Repository::factory()->for($other)->create();

    $this->get("/sys/fetch_changesets?key=secret-key&id={$project->identifier}")->assertOk();
    $this->get("/sys/fetch_changesets?key=secret-key&id={$project->id}")->assertOk();
    $this->get('/sys/fetch_changesets?key=secret-key&id=no-such-project')->assertNotFound();

    // Both requests target the same repository; the job is unique, so the
    // second dispatch is dropped while the first is still pending.
    Queue::assertPushed(RepositorySyncJob::class, 1);
    Queue::assertNotPushed(RepositorySyncJob::class, fn (RepositorySyncJob $job) => $job->uniqueId() !== (string) $repository->id);
});

test('fetch_changesets without an id queues every repository, and accepts POST without a CSRF token', function () {
    Queue::fake();
    sysEnable();
    Repository::factory()->for(sysProject())->create();
    Repository::factory()->for(sysProject())->create();
    Repository::factory()->for(sysProject(repositoryModule: false))->create();

    $this->post('/sys/fetch_changesets', ['key' => 'secret-key'])->assertOk();

    Queue::assertPushed(RepositorySyncJob::class, 2);
});

test('fetch_changesets does nothing without the key', function () {
    Queue::fake();
    sysEnable();
    Repository::factory()->for(sysProject())->create();

    $this->post('/sys/fetch_changesets', ['key' => 'nope'])->assertForbidden();

    Queue::assertNothingPushed();
});

test('the settings form saves the web service options and can generate a key', function () {
    $admin = User::factory()->admin()->create();

    $form = Livewire::actingAs($admin)->test('settings.index')->call('generateSysApiKey');
    $generated = $form->get('sys_api_key');
    expect(strlen($generated))->toBe(40);

    $form->set('sys_api_enabled', true)->call('save')->assertHasNoErrors();

    expect(Setting::get('sys_api_enabled'))->toBeTrue()
        ->and(Setting::get('sys_api_key'))->toBe($generated);
});

test('commit_access is a grantable repository permission', function () {
    $permission = app(PermissionRegistry::class)->get('commit_access');

    expect($permission)->not->toBeNull()
        ->and($permission->module)->toBe(ProjectModuleKey::Repository);
});
