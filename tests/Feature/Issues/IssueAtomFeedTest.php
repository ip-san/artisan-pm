<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;

function issueAtomMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function issueAtomStatus(bool $closed = false): IssueStatus
{
    return IssueStatus::factory()->create(['is_closed' => $closed]);
}

test('a member with view_issues can fetch the project issues atom feed, most recently updated first', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    $tracker = Tracker::factory()->create();
    $priority = Enumeration::factory()->create();
    $openStatus = issueAtomStatus();

    $older = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $openStatus->id, 'priority_id' => $priority->id,
        'subject' => 'Older issue', 'updated_at' => now()->subDay(),
    ]);
    $newer = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $openStatus->id, 'priority_id' => $priority->id,
        'subject' => 'Newer issue', 'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('issues.atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('<feed', false);
    $response->assertSeeInOrder(["#{$newer->id} Newer issue", "#{$older->id} Older issue"], false);
});

test('the issue atom feed excludes closed issues by default', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    $tracker = Tracker::factory()->create();
    $priority = Enumeration::factory()->create();

    $open = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => issueAtomStatus()->id, 'priority_id' => $priority->id, 'subject' => 'Open issue',
    ]);
    $closed = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => issueAtomStatus(closed: true)->id, 'priority_id' => $priority->id, 'subject' => 'Closed issue',
    ]);

    $response = $this->actingAs($user)->get(route('issues.atom', $project));

    $response->assertSee("#{$open->id} Open issue", false);
    $response->assertDontSee("#{$closed->id} Closed issue", false);
});

test('the issue atom feed only shows issues the viewer is allowed to see', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'own']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $tracker = Tracker::factory()->create();
    $priority = Enumeration::factory()->create();
    $status = issueAtomStatus();

    $mine = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'subject' => 'My issue', 'author_id' => $user->id,
    ]);
    $notMine = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'subject' => 'Someone else\'s issue',
    ]);

    $response = $this->actingAs($user)->get(route('issues.atom', $project));

    $response->assertSee("#{$mine->id} My issue", false);
    $response->assertDontSee("#{$notMine->id}", false);
});

test('a member without view_issues cannot fetch the project issues atom feed', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project, []);

    $this->actingAs($user)->get(route('issues.atom', $project))->assertForbidden();
});
