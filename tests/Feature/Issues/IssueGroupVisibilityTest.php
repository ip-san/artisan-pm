<?php

use App\Enums\IssueVisibility;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * A1-39: Redmine's default/own issue visibility counts an issue assigned to
 * one of the user's groups as their own (`assigned_to_id IN (user + groups)`),
 * resolved from the current group membership.
 *
 * @return object{project: Project, group: Group, member: User, outsider: User, issue: Issue}
 */
function groupVisibilityScenario(IssueVisibility $visibility): object
{
    $project = Project::factory()->create(['is_public' => false]);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => $visibility, 'assignable' => true]);

    $member = User::factory()->create();
    $outsider = User::factory()->create();

    foreach ([$member, $outsider] as $user) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    }

    $group = Group::factory()->create();
    $group->users()->attach($member);
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
        'assigned_to_group_id' => $group->id,
        'is_private' => $visibility === IssueVisibility::Default,
    ]);

    return (object) compact('project', 'group', 'member', 'outsider', 'issue');
}

test('a member of the assigned group sees the group\'s private issue under default visibility', function () {
    $s = groupVisibilityScenario(IssueVisibility::Default);

    expect($s->issue->isVisibleTo($s->member))->toBeTrue()
        ->and(Issue::query()->visibleTo($s->member, $s->project)->pluck('id')->all())->toBe([$s->issue->id])
        ->and(Issue::query()->visible($s->member)->pluck('id')->all())->toBe([$s->issue->id])
        ->and(Issue::filterVisible(collect([$s->issue]), $s->member))->toHaveCount(1);

    Livewire::actingAs($s->member)->test('issues.show', ['project' => $s->project, 'issue' => $s->issue])->assertOk();
});

test('a user outside the assigned group does not see the private issue', function () {
    $s = groupVisibilityScenario(IssueVisibility::Default);

    expect($s->issue->isVisibleTo($s->outsider))->toBeFalse()
        ->and(Issue::query()->visibleTo($s->outsider, $s->project)->exists())->toBeFalse()
        ->and(Issue::query()->visible($s->outsider)->exists())->toBeFalse()
        ->and(Issue::query()->visibleTo(null, $s->project)->exists())->toBeFalse();

    $this->actingAs($s->outsider)->get(route('issues.show', [$s->project, $s->issue]))->assertForbidden();

    Passport::actingAs($s->outsider);
    $this->getJson("/api/v1/issues/{$s->issue->id}")->assertForbidden();
});

test('own visibility counts issues assigned to the user\'s groups', function () {
    $s = groupVisibilityScenario(IssueVisibility::Own);

    expect($s->issue->isVisibleTo($s->member))->toBeTrue()
        ->and(Issue::query()->visibleTo($s->member, $s->project)->pluck('id')->all())->toBe([$s->issue->id])
        ->and($s->issue->isVisibleTo($s->outsider))->toBeFalse()
        ->and(Issue::query()->visibleTo($s->outsider, $s->project)->exists())->toBeFalse();
});

test('leaving the group takes the access away', function () {
    $s = groupVisibilityScenario(IssueVisibility::Default);

    expect($s->issue->isVisibleTo($s->member))->toBeTrue();

    $s->group->users()->detach($s->member);

    expect($s->issue->fresh()->isVisibleTo($s->member))->toBeFalse()
        ->and(Issue::query()->visibleTo($s->member, $s->project)->exists())->toBeFalse();

    $this->actingAs($s->member)->get(route('issues.show', [$s->project, $s->issue]))->assertForbidden();
});
