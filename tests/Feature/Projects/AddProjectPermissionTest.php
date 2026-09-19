<?php

use App\Enums\PermissionRequirement;
use App\Enums\RoleBuiltin;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Permissions\PermissionRegistry;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function addProjectForm(Tracker $tracker, string $identifier = 'brand-new'): array
{
    return ['name' => 'Brand New', 'identifier' => $identifier, 'trackerIds' => [$tracker->id]];
}

test('add_project is a global permission grantable to any signed-in role but not to anonymous', function () {
    $permission = app(PermissionRegistry::class)->get('add_project');

    expect($permission->requirement)->toBe(PermissionRequirement::LoggedIn)
        ->and(app(PermissionRegistry::class)->assignableTo(true))->not->toHaveKey('add_project')
        ->and(app(PermissionRegistry::class)->assignableTo(false, true))->toHaveKey('add_project');
});

test('a user whose role on some project grants add_project may create a top-level project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));
    $default = Role::factory()->create(['name' => 'Creator role', 'permissions' => ['view_issues']]);
    Setting::set('new_project_user_role_id', $default->id);
    $tracker = Tracker::factory()->create();

    $form = Livewire::actingAs($user)->test('projects.form');
    foreach (addProjectForm($tracker) as $key => $value) {
        $form->set($key, $value);
    }
    $form->call('save')->assertRedirect();

    $created = Project::where('identifier', 'brand-new')->firstOrFail();
    expect($created->parent_id)->toBeNull()
        ->and($created->members()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('the NonMember builtin role granting add_project lets every signed-in user create projects', function () {
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['add_project']]);
    $user = User::factory()->create();

    expect(app(AuthorizationService::class)->canGlobally($user, 'add_project'))->toBeTrue();
    Livewire::actingAs($user)->test('projects.form')->assertOk();
});

test('a user without any role granting add_project cannot create a top-level project', function () {
    $user = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    expect(app(AuthorizationService::class)->canGlobally($user, 'add_project'))->toBeFalse();
    Livewire::actingAs($user)->test('projects.form')->assertForbidden();
});

test('a role holding add_project only through another user\'s membership does not leak', function () {
    $holder = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($holder)->create()->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));
    $other = User::factory()->create();

    expect(app(AuthorizationService::class)->canGlobally($other, 'add_project'))->toBeFalse();
});

test('add_project through a group membership counts', function () {
    $user = User::factory()->create();
    $group = App\Models\Group::factory()->create();
    $group->users()->attach($user);
    Member::factory()->for(Project::factory()->create())->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));

    expect(app(AuthorizationService::class)->canGlobally($user, 'add_project'))->toBeTrue();
});

test('anonymous visitors and unknown permissions never hold a global permission', function () {
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['add_project']]);

    expect(app(AuthorizationService::class)->canGlobally(null, 'add_project'))->toBeFalse()
        ->and(app(AuthorizationService::class)->canGlobally(User::factory()->create(), 'no_such_permission'))->toBeFalse()
        ->and(app(AuthorizationService::class)->canGlobally(User::factory()->admin()->create(), 'add_project'))->toBeTrue();
});

test('the projects list offers the new project button to a holder only', function () {
    $holder = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($holder)->create()->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));
    $plain = User::factory()->create();

    Livewire::actingAs($holder)->test('projects.index')->assertSee(route('projects.create'), false);
    Livewire::actingAs($plain)->test('projects.index')->assertDontSee(route('projects.create'), false);
});

test('the api creates a top-level project for a holder and refuses everyone else', function () {
    $holder = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($holder)->create()->roles()->attach(Role::factory()->create(['permissions' => ['add_project']]));
    $tracker = Tracker::factory()->create();

    Passport::actingAs(User::factory()->create());
    $this->postJson('/api/v1/projects', ['name' => 'X', 'identifier' => 'x-denied', 'tracker_ids' => [$tracker->id]])->assertForbidden();

    Passport::actingAs($holder);
    $this->postJson('/api/v1/projects', ['name' => 'X', 'identifier' => 'x-allowed', 'tracker_ids' => [$tracker->id]])->assertCreated();
    expect(Project::where('identifier', 'x-allowed')->exists())->toBeTrue();
});
