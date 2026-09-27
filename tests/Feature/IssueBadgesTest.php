<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Ui\IssueBadges;

test('priorities above the default stand out, the top one most', function () {
    [$low, $normal, $high, $urgent, $immediate] = collect(['低め', '通常', '高め', '急いで', '今すぐ'])
        ->map(fn (string $name) => Enumeration::factory()->create(['name' => $name]))
        ->all();
    $normal->makeDefault();

    expect(IssueBadges::priorityLevel($low->fresh()))->toBe('lowest')
        ->and(IssueBadges::priorityLevel($normal->fresh()))->toBe('default')
        ->and(IssueBadges::priorityLevel($high->fresh()))->toBe('high')
        ->and(IssueBadges::priorityLevel($urgent->fresh()))->toBe('high')
        ->and(IssueBadges::priorityLevel($immediate->fresh()))->toBe('highest')
        ->and(IssueBadges::priorityTone($normal->fresh()))->toBe(IssueBadges::Neutral)
        ->and(IssueBadges::priorityTone($high->fresh()))->toBe(IssueBadges::Warning)
        ->and(IssueBadges::priorityTone($immediate->fresh()))->toBe(IssueBadges::Danger);
});

test('a status is toned by where it sits in the workflow', function () {
    $new = IssueStatus::factory()->create();
    $inProgress = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();

    expect(IssueBadges::statusTone($new))->toBe(IssueBadges::Brand)
        ->and(IssueBadges::statusTone($inProgress))->toBe(IssueBadges::Warning)
        ->and(IssueBadges::statusTone($closed))->toBe(IssueBadges::Success);
});

test('the issue list shows status and priority as badges', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('issues.index', $project))->assertOk()
        ->assertSee('data-status-badge', false)
        ->assertSee($issue->status->name);
});

test('an empty issue list offers to file one to members who may', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->withPermissions(['view_project', 'view_issues', 'add_issues'])->create());

    $this->actingAs($user)->get(route('issues.index', $project))->assertOk()
        ->assertSee('data-empty-state', false)
        ->assertSee('href="'.route('issues.create', $project).'"', false);
});

test('project deletion sits closed at the end of the overview', function () {
    $project = Project::factory()->create();

    $html = $this->actingAs(User::factory()->admin()->create())->get(route('projects.show', $project))->assertOk()->getContent();

    expect($html)->toMatch('#<details id="project-delete"(?![^>]*\sopen[\s>])#');
});
