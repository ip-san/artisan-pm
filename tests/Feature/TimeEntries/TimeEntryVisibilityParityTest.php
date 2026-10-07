<?php

use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Format\Hours;
use Livewire\Volt\Volt;

/**
 * Redmine decides what time a user may see from the roles that hold view_time_entries only
 * (User#allowed_to_view_all_time_entries?, Project.allowed_to_condition), builtin roles included,
 * and applies it everywhere logged time shows. These cover the paths a 2026-09 audit found
 * skipping it.
 */
function visibilityProjectWithIssue(bool $public = false): array
{
    $project = Project::factory()->create(['is_public' => $public]);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    return [$project, $issue];
}

function visibilityMember(Project $project, array $roles): User
{
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();

    foreach ($roles as [$permissions, $visibility]) {
        $member->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'time_entries_visibility' => $visibility]));
    }

    return $user;
}

function logHours(Project $project, Issue $issue, User $user, float $hours): TimeEntry
{
    return TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id, 'user_id' => $user->id, 'hours' => $hours, 'spent_on' => now()->toDateString()]);
}

test('only roles holding view_time_entries decide the visibility, so an extra role without it widens nothing', function () {
    [$project, $issue] = visibilityProjectWithIssue();
    $viewer = visibilityMember($project, [
        [['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::Own->value],
        [['view_project', 'view_issues'], TimeEntryVisibility::All->value],
    ]);
    $other = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::All->value]]);
    logHours($project, $issue, $viewer, 1.25);
    logHours($project, $issue, $other, 7.75);

    expect(app(AuthorizationService::class)->timeEntryVisibilityFor($viewer, $project))->toBe(TimeEntryVisibility::Own)
        ->and(app(AuthorizationService::class)->timeEntryVisibilityFor($other, $project))->toBe(TimeEntryVisibility::All);

    Volt::actingAs($viewer)->test('time-entries.index', ['project' => $project])->assertSee($viewer->displayName())->assertDontSee($other->displayName());
});

test('the Non member role\'s own visibility applies to a non-member of a public project', function () {
    // The builtin roles are seeded in a real install; a test database starts without them, and the
    // authorization service caches them on first use, so create it before anything else.
    Role::factory()->create(['name' => 'Non member', 'builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['view_project', 'view_issues', 'view_time_entries'], 'time_entries_visibility' => TimeEntryVisibility::Own->value]);
    [$project, $issue] = visibilityProjectWithIssue(public: true);
    $member = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::All->value]]);
    logHours($project, $issue, $member, 7.75);
    $outsider = User::factory()->create();
    logHours($project, $issue, $outsider, 1.25);

    expect(app(AuthorizationService::class)->timeEntryVisibilityFor($outsider, $project))->toBe(TimeEntryVisibility::Own);
    Volt::actingAs($outsider)->test('time-entries.index', ['project' => $project])->assertSee($outsider->displayName())->assertDontSee($member->displayName());
});

test('the activity feed shows a member with own visibility only their own time', function () {
    [$project, $issue] = visibilityProjectWithIssue();
    $viewer = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::Own->value]]);
    $other = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::All->value]]);
    logHours($project, $issue, $viewer, 1.25);
    logHours($project, $issue, $other, 7.75);

    // Time entries are off by default on the activity page (Redmine's activity scope), so switch them on.
    Volt::actingAs($viewer)->test('activity.index', ['project' => $project])->set('activeTypes', ['time-entry'])
        // Titles carry the raw hours; names would also match the page's member filter.
        ->assertSee('1.25')->assertDontSee('7.75');
});

test('the issue page shows logged time only with view_time_entries, and only the entries the viewer may see', function () {
    [$project, $issue] = visibilityProjectWithIssue();
    $blind = visibilityMember($project, [[['view_project', 'view_issues'], TimeEntryVisibility::All->value]]);
    $own = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::Own->value]]);
    $all = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::All->value]]);
    logHours($project, $issue, $own, 1.25);
    logHours($project, $issue, $all, 7.75);

    Volt::actingAs($blind)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertDontSee('工数 (')->assertDontSee(Hours::format(7.75));
    Volt::actingAs($own)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('工数 ('.Hours::format(1.25))->assertDontSee(Hours::format(7.75));
    Volt::actingAs($all)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('工数 ('.Hours::format(9.0));
});

test('the issue list offers spent hours only with view_time_entries and sums only the visible entries', function () {
    [$project, $issue] = visibilityProjectWithIssue();
    $blind = visibilityMember($project, [[['view_project', 'view_issues'], TimeEntryVisibility::All->value]]);
    $own = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::Own->value]]);
    $other = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::All->value]]);
    logHours($project, $issue, $own, 1.25);
    logHours($project, $issue, $other, 7.75);

    $blindList = Volt::actingAs($blind)->test('issues.index', ['project' => $project]);
    expect($blindList->instance()->availableColumns)->not->toHaveKey('spent_hours')
        ->and($blindList->instance()->totalChoices)->not->toHaveKey('spent_hours');

    $ownList = Volt::actingAs($own)->test('issues.index', ['project' => $project])->set('columns', ['subject', 'spent_hours']);
    expect($ownList->instance()->availableColumns)->toHaveKey('spent_hours');
    $ownList->assertSee(Hours::format(1.25))->assertDontSee(Hours::format(9.0));
});

test('the project overview totals time only for a viewer who may see every entry', function () {
    [$project, $issue] = visibilityProjectWithIssue();
    $own = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::Own->value]]);
    $all = visibilityMember($project, [[['view_project', 'view_issues', 'view_time_entries'], TimeEntryVisibility::All->value]]);
    logHours($project, $issue, $own, 1.25);
    logHours($project, $issue, $all, 7.75);

    Volt::actingAs($own)->test('projects.show', ['project' => $project])->assertDontSee('data-overview="time"', false);
    Volt::actingAs($all)->test('projects.show', ['project' => $project])->assertSee('data-overview="time"', false)->assertSee(Hours::format(9.0));
});

test('the Anonymous role has no time entry visibility setting, as in Redmine', function () {
    $admin = User::factory()->admin()->create();
    $anonymous = Role::factory()->create(['name' => 'Anonymous', 'builtin' => RoleBuiltin::Anonymous->value]);
    $regular = Role::factory()->create();

    Volt::actingAs($admin)->test('roles.form', ['role' => $anonymous])->assertDontSee('工数の閲覧範囲');
    Volt::actingAs($admin)->test('roles.form', ['role' => $regular])->assertSee('工数の閲覧範囲');
});
