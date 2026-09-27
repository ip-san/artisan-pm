<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Volt\Volt;

function overviewMember(Project $project, array $permissions, string $visibility = 'all', array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Member::factory()->for($project)->for($user)->create()->roles()
        ->attach(Role::factory()->withPermissions($permissions)->create(['name' => 'Developer', 'issues_visibility' => $visibility]));

    return $user;
}

test('the overview counts open and total issues per tracker, only those the viewer may see', function () {
    $project = Project::factory()->create();
    [$bug, $feature] = [Tracker::factory()->create(['name' => 'Bug']), Tracker::factory()->create(['name' => 'Feature'])];
    $project->trackers()->attach([$bug->id, $feature->id]);
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $viewer = overviewMember($project, ['view_project', 'view_issues'], 'own');

    Issue::factory()->for($project)->create(['tracker_id' => $bug->id, 'status_id' => $open->id, 'author_id' => $viewer->id]);
    Issue::factory()->for($project)->create(['tracker_id' => $bug->id, 'status_id' => $closed->id, 'author_id' => $viewer->id]);
    Issue::factory()->for($project)->create(['tracker_id' => $bug->id, 'status_id' => $open->id]); // someone else's, hidden under "own"

    $counts = Volt::actingAs($viewer)->test('projects.show', ['project' => $project])->instance()->issueCountsByTracker;

    expect($counts->map(fn (array $row) => [$row['tracker']->name, $row['open'], $row['total']])->all())
        ->toBe([['Bug', 1, 2], ['Feature', 0, 0]]);
});

test('the overview lists members by role and the latest news, and no issue box without view_issues', function () {
    $project = Project::factory()->create();
    $viewer = overviewMember($project, ['view_project', 'view_news'], 'all', ['firstname' => '花子', 'lastname' => '山田']);
    News::factory()->for($project)->create(['title' => '四月のリリース']);

    $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk()
        ->assertSee('data-overview="members"', false)
        ->assertSeeInOrder(['Developer', $viewer->displayName()])
        ->assertSee('四月のリリース')
        ->assertDontSee('data-overview="issues"', false);
});
