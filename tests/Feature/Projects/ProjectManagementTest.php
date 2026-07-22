<?php

use App\Enums\ProjectModuleKey;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Livewire\Livewire;

test('the new project form defaults modules and trackers from the admin-configured settings', function () {
    $admin = User::factory()->admin()->create();
    $allowedTracker = Tracker::factory()->create();
    Tracker::factory()->create();

    Setting::set('default_projects_modules', [ProjectModuleKey::IssueTracking->value, ProjectModuleKey::Wiki->value]);
    Setting::set('default_projects_tracker_ids', [$allowedTracker->id]);

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->assertSet('modules', [ProjectModuleKey::IssueTracking->value, ProjectModuleKey::Wiki->value])
        ->assertSet('trackerIds', [$allowedTracker->id]);
});

test('the new project form falls back to every tracker when no default tracker setting is configured', function () {
    $admin = User::factory()->admin()->create();
    $trackerA = Tracker::factory()->create();
    $trackerB = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->assertSet('trackerIds', [$trackerA->id, $trackerB->id]);
});

test('an admin can create a project with modules and trackers through the form', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'New Project')
        ->set('identifier', 'new-project')
        ->set('modules', [ProjectModuleKey::IssueTracking->value, ProjectModuleKey::Wiki->value])
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertRedirect();

    $project = Project::where('identifier', 'new-project')->firstOrFail();

    expect($project->name)->toBe('New Project')
        ->and($project->hasModule(ProjectModuleKey::IssueTracking))->toBeTrue()
        ->and($project->hasModule(ProjectModuleKey::Boards))->toBeFalse()
        ->and($project->trackers->pluck('id')->all())->toBe([$tracker->id]);
});

test('a project must have at least one tracker', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.form')
        ->set('name', 'New Project')
        ->set('identifier', 'new-project')
        ->call('save')
        ->assertHasErrors(['trackerIds']);
});

test('a non-admin without the create permission cannot open the project form', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.form')->assertForbidden();
});

test('a project member with edit_project can update the project but not delete it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Old name']);
    $project->trackers()->attach(Tracker::factory()->create());
    $role = Role::factory()->create(['permissions' => ['edit_project']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.form', ['project' => $project])
        ->set('name', 'Updated name')
        ->call('save')
        ->assertRedirect();

    expect($project->refresh()->name)->toBe('Updated name')
        ->and($user->can('delete', $project))->toBeFalse();
});

test('the project policy allows guests to view a public project but not a private one', function () {
    // The Livewire project routes are gated behind the `auth` middleware for
    // now (see routes/web.php) — guest-accessible browsing is Phase 1+ scope.
    // This exercises the Policy/AuthorizationService layer directly, which
    // already supports the anonymous-role resolution those future routes
    // will rely on.
    $policy = app(ProjectPolicy::class);

    $public = Project::factory()->create();
    $private = Project::factory()->private()->create();

    expect($policy->view(null, $public))->toBeTrue()
        ->and($policy->view(null, $private))->toBeFalse();
});

test('a non-member cannot manage members of a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)->test('projects.members', ['project' => $project])->assertForbidden();
});

test('a tracker in use by an issue in the project cannot be unchecked', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $otherTracker = Tracker::factory()->create();
    $project->trackers()->attach([$tracker->id, $otherTracker->id]);
    Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('trackerIds', [$otherTracker->id])
        ->call('save')
        ->assertHasErrors(['trackerIds']);

    expect($project->trackers()->pluck('trackers.id'))->toContain($tracker->id);
});

test('an unused tracker can still be removed from a project', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $keptTracker = Tracker::factory()->create();
    $project->trackers()->attach([$tracker->id, $keptTracker->id]);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('trackerIds', [$keptTracker->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($project->trackers()->pluck('trackers.id'))->not->toContain($tracker->id);
});
