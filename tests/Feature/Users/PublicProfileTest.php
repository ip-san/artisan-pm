<?php

use App\Enums\UsersVisibility;
use App\Models\Group;
use App\Models\Issue;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

test('the admin user list links each user\'s name to their public profile', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('users.index')
        ->assertSee(route('users.show', $target), false);
});

test('a project\'s member list links a visible member\'s name to their public profile', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['manage_members']]);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($role);
    $target = User::factory()->create();
    Member::factory()->for($project)->for($target)->create();

    Livewire::actingAs($viewer)
        ->test('projects.members', ['project' => $project])
        ->assertSee(route('users.show', $target), false);
});

test('a project\'s member list does not link a locked fellow member\'s name to a profile a non-admin viewer can\'t reach', function () {
    // Membership isn't automatically revoked when an account is locked, so
    // a locked user can still appear in a project's member list — but
    // isVisibleTo() (unlike scopeVisibleTo() alone) excludes non-Active
    // targets for non-admin viewers, so the name must render as plain
    // text here rather than a link to a page that would 404.
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['manage_members']]);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($role);
    $lockedMember = User::factory()->create(['status' => 'locked']);
    Member::factory()->for($project)->for($lockedMember)->create();

    Livewire::actingAs($viewer)
        ->test('projects.members', ['project' => $project])
        ->assertSee($lockedMember->name)
        ->assertDontSee(route('users.show', $lockedMember), false);
});

test('a user can view their own profile', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('users.show', ['user' => $user])
        ->assertOk()
        ->assertSee($user->name);
});

test('an admin can view any user\'s profile regardless of status', function () {
    $admin = User::factory()->admin()->create();
    $locked = User::factory()->create(['status' => 'locked']);

    Livewire::actingAs($admin)
        ->test('users.show', ['user' => $locked])
        ->assertOk();
});

test('a non-admin viewer with a role defaulting to site-wide user visibility can view any active user\'s profile', function () {
    // Role::users_visibility defaults to 'all' (see Role model), so a
    // plain membership anywhere — not necessarily sharing a project with
    // the target — is enough to grant this, matching how
    // hasSiteWideUserVisibility() consults the viewer's own roles only.
    $project = Project::factory()->create();
    $role = Role::factory()->create();
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($role);

    $target = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test('users.show', ['user' => $target])
        ->assertOk();
});

test('a non-admin viewer with a members_of_visible_projects role cannot view an unrelated user\'s profile', function () {
    $project = Project::factory()->create();
    $restrictedRole = Role::factory()->create(['users_visibility' => UsersVisibility::MembersOfVisibleProjects->value]);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($restrictedRole);

    $unrelatedTarget = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test('users.show', ['user' => $unrelatedTarget])
        ->assertNotFound();
});

test('a non-admin viewer with a members_of_visible_projects role can view a fellow project member\'s profile', function () {
    $project = Project::factory()->create();
    $restrictedRole = Role::factory()->create(['users_visibility' => UsersVisibility::MembersOfVisibleProjects->value]);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($restrictedRole);

    $fellowMember = User::factory()->create();
    Member::factory()->for($project)->for($fellowMember)->create()->roles()->attach($restrictedRole);

    Livewire::actingAs($viewer)
        ->test('users.show', ['user' => $fellowMember])
        ->assertOk();
});

test('a non-admin viewer cannot view a locked user\'s profile', function () {
    $viewer = User::factory()->create();
    $locked = User::factory()->create(['status' => 'locked']);

    Livewire::actingAs($viewer)
        ->test('users.show', ['user' => $locked])
        ->assertNotFound();
});

test('the profile shows issue counts scoped to projects the viewer holds view_issues in, not every project the profile owner belongs to', function () {
    $sharedProject = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);

    $viewer = User::factory()->create();
    Member::factory()->for($sharedProject)->for($viewer)->create()->roles()->attach($role);

    $target = User::factory()->create();
    Member::factory()->for($sharedProject)->for($target)->create()->roles()->attach($role);
    Member::factory()->for($otherProject)->for($target)->create();

    $visibleIssue = Issue::factory()->for($sharedProject)->create(['assigned_to_id' => $target->id]);
    Issue::factory()->for($otherProject)->create(['assigned_to_id' => $target->id]);

    $component = Livewire::actingAs($viewer)->test('users.show', ['user' => $target]);

    expect($component->get('issueCounts')['assigned']['total'])->toBe(1)
        ->and($visibleIssue->exists)->toBeTrue();
});

test('the profile\'s project membership list only shows projects visible to the viewer', function () {
    $viewer = User::factory()->create();
    $visibleProject = Project::factory()->create(['is_public' => true, 'name' => 'Visible Project']);
    $privateProject = Project::factory()->create(['is_public' => false, 'name' => 'Private Project']);
    $target = User::factory()->create();

    Member::factory()->for($visibleProject)->for($target)->create();
    Member::factory()->for($privateProject)->for($target)->create();

    Livewire::actingAs($viewer)
        ->test('users.show', ['user' => $target])
        ->assertSee('Visible Project')
        ->assertDontSee('Private Project');
});

test('group membership is hidden from a viewer who is neither the profile owner nor an admin', function () {
    $sharedProject = Project::factory()->create(['is_public' => true]);
    $viewer = User::factory()->create();
    $target = User::factory()->create();
    Member::factory()->for($sharedProject)->for($target)->create();

    $group = Group::factory()->create(['name' => 'Secret Group']);
    $group->users()->attach($target);

    Livewire::actingAs($viewer)
        ->test('users.show', ['user' => $target])
        ->assertDontSee('Secret Group');
});

test('group membership is shown to the profile owner themselves', function () {
    $target = User::factory()->create();
    $group = Group::factory()->create(['name' => 'My Group']);
    $group->users()->attach($target);

    Livewire::actingAs($target)
        ->test('users.show', ['user' => $target])
        ->assertSee('My Group');
});

test('recent activity only includes entries authored by the profile\'s user, not other authors in the same project', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_news']]);

    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($role);

    $target = User::factory()->create();
    Member::factory()->for($project)->for($target)->create()->roles()->attach($role);

    $ownNews = News::factory()->for($project)->create(['author_id' => $target->id, 'title' => 'By Target']);
    News::factory()->for($project)->create(['author_id' => $viewer->id, 'title' => 'By Someone Else']);

    $component = Livewire::actingAs($viewer)->test('users.show', ['user' => $target]);

    $titles = $component->get('recentActivity')->pluck('title');
    expect($titles)->toContain('By Target')
        ->not->toContain('By Someone Else')
        ->and($ownNews->exists)->toBeTrue();
});

test('recent activity older than the 30-day window is excluded', function () {
    // Every ActivityProvider::entries() call is an unbounded query (no
    // author or limit pushed into SQL — see recentActivity()'s own doc),
    // so this window bound is what keeps the page's query cost sane; this
    // asserts the bound is actually being applied, not just documented.
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_news']]);

    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($role);

    $target = User::factory()->create();
    Member::factory()->for($project)->for($target)->create()->roles()->attach($role);

    News::factory()->for($project)->create(['author_id' => $target->id, 'created_at' => now()->subDays(31)]);

    $component = Livewire::actingAs($viewer)->test('users.show', ['user' => $target]);

    expect($component->get('recentActivity'))->toHaveCount(0);
});
