<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Livewire\Livewire;

function journalDiffIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create(array_merge([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'description' => 'the quick fox',
    ], $attributes));
}

function journalDiffMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('changing the description creates a diffable journal detail', function () {
    $project = Project::factory()->create();
    $issue = journalDiffIssue($project);
    $author = journalDiffMember($project, ['view_issues', 'edit_issues']);

    app(IssueService::class)->update($issue, ['description' => 'the quick brown fox jumps'], $author);

    $detail = JournalDetail::whereHas('journal', fn ($q) => $q->where('issue_id', $issue->id))
        ->where('property', 'attr')->where('prop_key', 'description')->firstOrFail();

    expect($detail->old_value)->toBe('the quick fox')
        ->and($detail->new_value)->toBe('the quick brown fox jumps');
});

test('the diff page shows additions and deletions between the old and new description', function () {
    $project = Project::factory()->create();
    $issue = journalDiffIssue($project);
    $author = journalDiffMember($project, ['view_issues', 'edit_issues']);
    app(IssueService::class)->update($issue, ['description' => 'the quick brown fox jumps'], $author);

    $detail = JournalDetail::whereHas('journal', fn ($q) => $q->where('issue_id', $issue->id))
        ->where('property', 'attr')->where('prop_key', 'description')->firstOrFail();

    $user = journalDiffMember($project);

    $component = Livewire::actingAs($user)
        ->test('issues.journal-detail-diff', ['project' => $project, 'issue' => $issue, 'journalDetail' => $detail]);

    $component->assertOk();

    $added = collect($component->get('diff'))->where('type', 'add')->pluck('text')->implode('');
    expect($added)->toContain('brown')->toContain('jumps');
});

test('a detail belonging to a different issue 404s', function () {
    $project = Project::factory()->create();
    $issue = journalDiffIssue($project);
    $otherIssue = journalDiffIssue($project);
    $author = journalDiffMember($project, ['view_issues', 'edit_issues']);
    app(IssueService::class)->update($otherIssue, ['description' => 'changed'], $author);

    $detail = JournalDetail::whereHas('journal', fn ($q) => $q->where('issue_id', $otherIssue->id))
        ->where('property', 'attr')->where('prop_key', 'description')->firstOrFail();

    $user = journalDiffMember($project);

    Livewire::actingAs($user)
        ->test('issues.journal-detail-diff', ['project' => $project, 'issue' => $issue, 'journalDetail' => $detail])
        ->assertStatus(404);
});

test('a non-description detail 404s', function () {
    $project = Project::factory()->create();
    $issue = journalDiffIssue($project);
    $author = journalDiffMember($project, ['view_issues', 'edit_issues']);
    app(IssueService::class)->update($issue, ['subject' => 'Renamed'], $author);

    $detail = JournalDetail::whereHas('journal', fn ($q) => $q->where('issue_id', $issue->id))
        ->where('prop_key', 'subject')->firstOrFail();

    $user = journalDiffMember($project);

    Livewire::actingAs($user)
        ->test('issues.journal-detail-diff', ['project' => $project, 'issue' => $issue, 'journalDetail' => $detail])
        ->assertStatus(404);
});

test('a user without view_issues cannot view the diff', function () {
    $project = Project::factory()->private()->create();
    $issue = journalDiffIssue($project);
    $author = journalDiffMember($project, ['view_issues', 'edit_issues']);
    app(IssueService::class)->update($issue, ['description' => 'changed'], $author);

    $detail = JournalDetail::whereHas('journal', fn ($q) => $q->where('issue_id', $issue->id))
        ->where('property', 'attr')->where('prop_key', 'description')->firstOrFail();

    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test('issues.journal-detail-diff', ['project' => $project, 'issue' => $issue, 'journalDetail' => $detail])
        ->assertForbidden();
});

test('the issue show page links to the diff for a description change', function () {
    $project = Project::factory()->create();
    $issue = journalDiffIssue($project);
    $author = journalDiffMember($project, ['view_issues', 'edit_issues']);
    app(IssueService::class)->update($issue, ['description' => 'changed'], $author);

    $detail = JournalDetail::whereHas('journal', fn ($q) => $q->where('issue_id', $issue->id))
        ->where('property', 'attr')->where('prop_key', 'description')->firstOrFail();

    $user = journalDiffMember($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee(route('issues.journal-detail-diff', [$project, $issue, $detail]));
});
