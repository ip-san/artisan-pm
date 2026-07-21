<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

function atomFeedMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view access can fetch the activity atom feed', function () {
    $project = Project::factory()->create();
    $user = atomFeedMember($project, ['view_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Feed test issue', 'created_at' => now()->subDay()]);

    $response = $this->actingAs($user)->get(route('activity.atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('<feed', false);
    $response->assertSee('Feed test issue', false);
    expect($issue->id)->toBeGreaterThan(0);
});

test('an entry outside the default 10-day window is excluded', function () {
    $project = Project::factory()->create();
    $user = atomFeedMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['subject' => 'Too old for the feed', 'created_at' => now()->subDays(30)]);

    $response = $this->actingAs($user)->get(route('activity.atom', $project));

    $response->assertOk()->assertDontSee('Too old for the feed', false);
});

test('a user without view access cannot fetch the feed', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('activity.atom', $project))->assertForbidden();
});

test('a guest is redirected to login', function () {
    $project = Project::factory()->create();

    $this->get(route('activity.atom', $project))->assertRedirect(route('login'));
});
