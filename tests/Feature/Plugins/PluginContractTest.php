<?php

use App\CustomFields\FormatRegistry;
use App\CustomFields\Formats\FormatContract;
use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Plugins\MenuItem;
use App\Support\Plugins\PluginManager;
use Livewire\Livewire;
use Tests\Fixtures\Plugins\SamplePlugin\SamplePluginServiceProvider;

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int, author_id: int}
 */
function pluginTestIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
    ];
}

test('a plugin can register a new permission through PluginManager', function () {
    $this->app->register(SamplePluginServiceProvider::class);

    expect(app(PermissionRegistry::class)->has(SamplePluginServiceProvider::PERMISSION_KEY))->toBeTrue();
});

test('a plugin-registered permission is assignable to a role like any core permission', function () {
    $this->app->register(SamplePluginServiceProvider::class);

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('roles.form')
        ->set('name', 'Plugin-aware role')
        ->set('permissions', [SamplePluginServiceProvider::PERMISSION_KEY])
        ->call('save');

    $role = Role::where('name', 'Plugin-aware role')->firstOrFail();
    expect($role->hasPermission(SamplePluginServiceProvider::PERMISSION_KEY))->toBeTrue();
});

test('a plugin-registered menu item appears in the nav', function () {
    $this->app->register(SamplePluginServiceProvider::class);

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.index'))
        ->assertOk()
        ->assertSee('サンプルプラグイン')
        ->assertSee('/sample-plugin', escape: false);
});

test('the nav has no plugin menu item when no plugin is registered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.index'))
        ->assertOk()
        ->assertDontSee('サンプルプラグイン');
});

test('a plugin-registered view hook fires on the issue show page', function () {
    $this->app->register(SamplePluginServiceProvider::class);

    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create(pluginTestIssueDefaults());

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee("Sample plugin says hello for issue #{$issue->id}", escape: false);
});

test('the issue show page has no plugin hook output when no plugin is registered', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create(pluginTestIssueDefaults());

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertDontSee('data-sample-plugin-hook', escape: false);
});

test('renderHook concatenates output from multiple registered renderers', function () {
    $manager = app(PluginManager::class);

    $manager->registerViewHook('test.hook', fn () => 'first');
    $manager->registerViewHook('test.hook', fn () => 'second');

    expect($manager->renderHook('test.hook'))->toBe('firstsecond');
});

test('a menu item with a visibility callback is hidden when the callback returns false', function () {
    $manager = app(PluginManager::class);

    $manager->registerMenuItem('nav', new MenuItem('Hidden', '/hidden', fn () => false));
    $manager->registerMenuItem('nav', new MenuItem('Shown', '/shown', fn () => true));

    $labels = collect($manager->menuItems('nav'))->pluck('label');

    expect($labels)->toContain('Shown')->not->toContain('Hidden');
});

test('a plugin can register a custom field format', function () {
    $manager = app(PluginManager::class);

    // A plugin adding a genuinely new format enum case is out of scope for
    // this contract test (CustomFieldFormat is a closed native enum, same
    // deliberate scope cut as project modules and filter operators) — this
    // exercises re-registering a format for an existing key, proving the
    // registry call itself is reachable through PluginManager.
    $format = new class implements FormatContract
    {
        public function key(): CustomFieldFormat
        {
            return CustomFieldFormat::String;
        }

        public function label(): string
        {
            return 'plugin-string';
        }

        public function storageColumn(): string
        {
            return 'value_string';
        }

        public function prepareValue(mixed $input): mixed
        {
            return $input;
        }

        public function castValue(mixed $stored): mixed
        {
            return $stored;
        }

        public function validationRules(CustomField $field): array
        {
            return [];
        }
    };

    $manager->registerCustomFieldFormat($format);

    expect(app(FormatRegistry::class)->get(CustomFieldFormat::String)->label())->toBe('plugin-string');
});
