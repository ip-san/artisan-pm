<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function markdownRenderingIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create(array_merge([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ], $attributes));
}

function markdownRenderingMember(Project $project, array $permissions = ['view_issues', 'edit_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('the issue description is rendered as Markdown, including an issue link', function () {
    $project = Project::factory()->create();
    $linkedIssue = markdownRenderingIssue($project);
    $issue = markdownRenderingIssue($project, ['description' => "See issue #{$linkedIssue->id} for context."]);
    $user = markdownRenderingMember($project);

    $html = Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->get('renderedDescription');

    expect($html)->toContain(route('issues.show', [$project, $linkedIssue]));
});

test('a bare-filename image reference in the description resolves against the issue\'s own attachments', function () {
    $project = Project::factory()->create();
    $issue = markdownRenderingIssue($project, ['description' => 'See ![](screenshot.png) below.']);
    $media = $issue->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');
    $user = markdownRenderingMember($project);

    $html = Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->get('renderedDescription');

    expect($html)->toContain(route('attachments.show', $media));
});

test('a journal comment is rendered as Markdown too', function () {
    $project = Project::factory()->create();
    $issue = markdownRenderingIssue($project);
    $user = markdownRenderingMember($project);

    $journal = $issue->journals()->create([
        'user_id' => $user->id,
        'notes' => "Please see issue #{$issue->id} again.",
    ]);

    $component = Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue]);

    $html = $component->instance()->renderedNotes($journal);

    expect($html)->toContain(route('issues.show', [$project, $issue]));
});
