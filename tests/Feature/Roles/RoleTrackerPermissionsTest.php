<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Livewire\Livewire;

test('an existing role without settings allows every tracker', function () {
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);

    expect($role->permissionsAllTrackers('view_issues'))->toBeTrue()
        ->and($role->trackerIdsFor('view_issues'))->toBeNull()
        ->and($role->permissionsAllTrackers('edit_issues'))->toBeFalse()
        ->and($role->trackerIdsFor('edit_issues'))->toBe([]);
});

test('a permission can be limited to selected trackers', function () {
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $role = Role::factory()->limitedToTrackers('view_issues', [$bug->id])->create(['permissions' => ['view_issues', 'add_issues']]);

    $role->refresh();

    expect($role->permissionsAllTrackers('view_issues'))->toBeFalse()
        ->and($role->trackerIdsFor('view_issues'))->toBe([$bug->id])
        ->and($role->allowsPermissionOnTracker('view_issues', $bug->id))->toBeTrue()
        ->and($role->allowsPermissionOnTracker('view_issues', $feature->id))->toBeFalse()
        ->and($role->trackerIdsFor('add_issues'))->toBeNull();
});

test('the role form saves the permission and tracker table', function () {
    $admin = User::factory()->admin()->create();
    $bug = Tracker::factory()->create(['name' => 'Bug']);
    $feature = Tracker::factory()->create(['name' => 'Feature']);

    Livewire::actingAs($admin)
        ->test('roles.form')
        ->assertSee('Bug')
        ->assertSee('Feature')
        ->set('name', 'Bug reporter')
        ->set('permissions', ['view_issues', 'add_issues'])
        ->set('permissionsAllTrackers.view_issues', false)
        ->set('permissionTrackerIds.view_issues', [(string) $bug->id])
        ->set('permissionsAllTrackers.add_issues', false)
        ->set('permissionTrackerIds.add_issues', [])
        ->call('save')
        ->assertRedirect(route('roles.index'));

    $role = Role::where('name', 'Bug reporter')->firstOrFail();

    expect($role->trackerIdsFor('view_issues'))->toBe([$bug->id])
        ->and($role->trackerIdsFor('add_issues'))->toBe([])
        ->and($role->trackerIdsFor('edit_issues'))->toBe([]);
});

test('editing a role loads its tracker limits and can widen them back to all trackers', function () {
    $admin = User::factory()->admin()->create();
    $bug = Tracker::factory()->create();
    $role = Role::factory()->limitedToTrackers('view_issues', [$bug->id])->create(['permissions' => ['view_issues']]);

    Livewire::actingAs($admin)
        ->test('roles.form', ['role' => $role])
        ->assertSet('permissionsAllTrackers.view_issues', false)
        ->assertSet('permissionTrackerIds.view_issues', [$bug->id])
        ->set('permissionsAllTrackers.view_issues', true)
        ->call('save');

    expect($role->refresh()->trackerIdsFor('view_issues'))->toBeNull();
});

test('copying a role copies its tracker limits', function () {
    $admin = User::factory()->admin()->create();
    $bug = Tracker::factory()->create();
    $source = Role::factory()->limitedToTrackers('edit_issues', [$bug->id])->create(['permissions' => ['view_issues', 'edit_issues']]);

    Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($admin)
        ->test('roles.form')
        ->assertSet('permissionsAllTrackers.edit_issues', false)
        ->assertSet('permissionTrackerIds.edit_issues', [$bug->id]);
});

test('allowedTrackerIds unions the trackers of every role, including group-derived ones', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $support = Tracker::factory()->create();
    $user = User::factory()->create();

    $direct = Role::factory()->limitedToTrackers('view_issues', [$bug->id])->create(['permissions' => ['view_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($direct);

    $authorization = app(AuthorizationService::class);

    expect($authorization->allowedTrackerIds($user, $project, 'view_issues')->all())->toBe([$bug->id])
        ->and($authorization->allowedTrackerIds($user, $project, 'add_issues')->all())->toBe([]);

    $group = Group::factory()->create();
    $group->users()->attach($user);
    $viaGroup = Role::factory()->limitedToTrackers('view_issues', [$feature->id])->create(['permissions' => ['view_issues']]);
    Member::factory()->for($project)->create(['group_id' => $group->id, 'user_id' => null])->roles()->attach($viaGroup);

    expect($authorization->allowedTrackerIds($user, $project, 'view_issues')->sort()->values()->all())->toBe([$bug->id, $feature->id])
        ->and($authorization->canOnTracker($user, 'view_issues', $project, $support->id))->toBeFalse();

    $unrestricted = Role::factory()->create(['permissions' => ['view_issues']]);
    Member::query()->where('project_id', $project->id)->where('user_id', $user->id)->first()->roles()->attach($unrestricted);

    expect($authorization->allowedTrackerIds($user, $project, 'view_issues'))->toBeNull()
        ->and($authorization->allowedTrackerIds(User::factory()->admin()->create(), $project, 'view_issues'))->toBeNull();
});
