<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function issuePreviewMember(Project $project, array $permissions = ['view_issues', 'add_issues', 'edit_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('the preview is hidden until toggled', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $user = issuePreviewMember($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('description', 'Hello **world**')
        ->assertSet('showPreview', false)
        ->assertDontSee('<strong>world</strong>', false);
});

test('toggling the preview renders the current description as Markdown', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $otherIssue = Issue::factory()->for($project)->create();
    $user = issuePreviewMember($project);

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('description', "Hello **world**, see issue #{$otherIssue->id}.")
        ->call('togglePreview');

    $component->assertSet('showPreview', true)
        ->assertSee('<strong>world</strong>', false)
        ->assertSee(route('issues.show', [$project, $otherIssue]), false);
});

test('toggling twice hides the preview again', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $user = issuePreviewMember($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->call('togglePreview')
        ->assertSet('showPreview', false);
});

test('an empty preview shows a placeholder instead of nothing', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $user = issuePreviewMember($project);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('description', '')
        ->call('togglePreview')
        ->assertSee('本文が空です');
});

test('previewing an existing issue resolves inline images against its own attachments', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');
    $user = issuePreviewMember($project);

    $component = Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('description', '![](screenshot.png)')
        ->call('togglePreview');

    expect($component->get('previewHtml'))->toContain(route('attachments.show', $issue->attachments()->first()));
});
