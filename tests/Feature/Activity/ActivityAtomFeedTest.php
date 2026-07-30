<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
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

test('an entry outside the default activity_days_default window is excluded', function () {
    $project = Project::factory()->create();
    $user = atomFeedMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['subject' => 'Too old for the feed', 'created_at' => now()->subDays(30)]);

    $response = $this->actingAs($user)->get(route('activity.atom', $project));

    $response->assertOk()->assertDontSee('Too old for the feed', false);
});

test('an unset activity_days_default narrows the atom feed to 7 days, not Redmine\'s own 10-day default', function () {
    // Pins an intentional deviation: this app's ActivityFeedController used
    // to hardcode 10 days independently of any setting. Now that it shares
    // Setting::activity_days_default with the HTML activity views (whose
    // own pre-existing hardcoded default was 7, not 10), the two stay
    // consistent with each other rather than each hardcoding a different
    // number — but that means the unset-setting Atom window is narrower
    // than Redmine's own default. An entry 8 days old is the case that
    // distinguishes the two: present under a 10-day window, absent under 7.
    $project = Project::factory()->create();
    $user = atomFeedMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['subject' => 'Eight days old', 'created_at' => now()->subDays(8)]);

    $response = $this->actingAs($user)->get(route('activity.atom', $project));

    $response->assertOk()->assertDontSee('Eight days old', false);
});

test('activity_days_default widens the atom feed window when configured', function () {
    Setting::set('activity_days_default', 30);
    $project = Project::factory()->create();
    $user = atomFeedMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['subject' => 'Twenty days old', 'created_at' => now()->subDays(20)]);

    $response = $this->actingAs($user)->get(route('activity.atom', $project));

    $response->assertOk()->assertSee('Twenty days old', false);
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
