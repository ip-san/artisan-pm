<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function urlFilterMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function urlFilterIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([...[
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ], ...$attributes]);
}

test('a fixed_version_id filter supplied via the URL query string pre-filters the issue list', function () {
    $project = Project::factory()->create();
    $user = urlFilterMember($project);
    $version = Version::factory()->for($project)->create();
    $matching = urlFilterIssue($project, ['fixed_version_id' => $version->id]);
    $other = urlFilterIssue($project);

    $component = Livewire::actingAs($user)
        ->withQueryParams([
            'activeFilterKeys' => ['fixed_version_id'],
            'filterOperators' => ['fixed_version_id' => '='],
            'filterValues' => ['fixed_version_id' => [$version->id]],
        ])
        ->test('issues.index', ['project' => $project]);

    $ids = $component->get('issues')->pluck('id');

    expect($ids)->toContain($matching->id)
        ->not->toContain($other->id);
});

test('an "in" filter with multiple status ids supplied via the URL matches any of them', function () {
    $project = Project::factory()->create();
    $user = urlFilterMember($project);
    $openStatus = IssueStatus::factory()->create(['is_closed' => false]);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);
    $otherStatus = IssueStatus::factory()->create(['is_closed' => false]);
    $openIssue = urlFilterIssue($project, ['status_id' => $openStatus->id]);
    $closedIssue = urlFilterIssue($project, ['status_id' => $closedStatus->id]);
    $otherIssue = urlFilterIssue($project, ['status_id' => $otherStatus->id]);

    $component = Livewire::actingAs($user)
        ->withQueryParams([
            // statusFilter defaults to 'open' via its own #[Url] quick-toggle
            // (see issues/index.blade.php), independent of the filter
            // builder — set to 'all' here so it doesn't mask the closed
            // issue this test expects the status_id "in" filter to surface.
            'statusFilter' => 'all',
            'activeFilterKeys' => ['status_id'],
            'filterOperators' => ['status_id' => 'in'],
            'filterValues' => ['status_id' => [$openStatus->id, $closedStatus->id]],
        ])
        ->test('issues.index', ['project' => $project]);

    $ids = $component->get('issues')->pluck('id');

    expect($ids)->toContain($openIssue->id)
        ->toContain($closedIssue->id)
        ->not->toContain($otherIssue->id);
});

test('an active filter key with no matching operator in the URL is silently ignored', function () {
    $project = Project::factory()->create();
    $user = urlFilterMember($project);
    $issue = urlFilterIssue($project);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['activeFilterKeys' => ['fixed_version_id']])
        ->test('issues.index', ['project' => $project]);

    expect($component->get('issues')->pluck('id'))->toContain($issue->id);
});
