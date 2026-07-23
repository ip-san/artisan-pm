<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function defaultsProjectMember(Project $project, array $permissions = ['view_issues', 'add_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a new issue defaults its start date to today', function () {
    $project = Project::factory()->create();
    $user = defaultsProjectMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('start_date'))->toBe(Carbon::now()->toDateString());
});

test('a new issue has no default due date when default_issue_due_date_offset is unset', function () {
    $project = Project::factory()->create();
    $user = defaultsProjectMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('due_date'))->toBeNull();
});

test('a new issue defaults its due date to today plus the configured offset', function () {
    Setting::set('default_issue_due_date_offset', 5);

    $project = Project::factory()->create();
    $user = defaultsProjectMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('due_date'))->toBe(Carbon::now()->addDays(5)->toDateString());
});

test('a due date offset of zero defaults the due date to today', function () {
    Setting::set('default_issue_due_date_offset', 0);

    $project = Project::factory()->create();
    $user = defaultsProjectMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    expect($component->get('due_date'))->toBe(Carbon::now()->toDateString());
});

test('a copy_from source\'s own due date takes precedence over the configured offset default', function () {
    Setting::set('default_issue_due_date_offset', 5);

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = defaultsProjectMember($project);

    $source = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'due_date' => '2026-03-01',
    ]);

    $component = Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($user)
        ->test('issues.form', ['project' => $project]);

    expect($component->get('due_date'))->toBe('2026-03-01');
});

test('editing an existing issue does not apply the default due date offset', function () {
    Setting::set('default_issue_due_date_offset', 5);

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = defaultsProjectMember($project, ['view_issues', 'edit_issues']);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'due_date' => null,
    ]);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue]);

    expect($component->get('due_date'))->toBeNull();
});

test('the assign-to-me shortcut is offered to a project member and hides once used', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = defaultsProjectMember($project);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project]);

    $component->assertSee('自分に割り当てる');

    $component->set('assigned_to_id', $user->id)->assertDontSee('自分に割り当てる');
});

test('the assign-to-me shortcut is not offered to a non-member', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('issues.form', ['project' => $project])
        ->assertDontSee('自分に割り当てる');
});
