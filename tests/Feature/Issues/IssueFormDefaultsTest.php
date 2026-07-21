<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
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
