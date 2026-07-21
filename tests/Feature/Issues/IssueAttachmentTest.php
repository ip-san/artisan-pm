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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('uploading files on issue creation attaches them to the issue', function () {
    Storage::fake('public');

    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Issue with an attachment')
        ->set('newAttachments', [UploadedFile::fake()->create('screenshot.png', 500)])
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Issue with an attachment')->firstOrFail();

    expect($issue->attachments())->toHaveCount(1)
        ->and($issue->attachments()->first()->file_name)->toBe('screenshot.png');
});

test('an oversized attachment is rejected by validation', function () {
    Storage::fake('public');

    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    // 11000 KB: under Livewire's own default temporary-upload cap (12288 KB,
    // config('livewire.temporary_file_upload.rules')) so the file actually
    // reaches the component, but over this form's own 10240 KB (10 MB) rule.
    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Issue with a too-large file')
        ->set('newAttachments', [UploadedFile::fake()->create('huge.zip', 11000)])
        ->call('save')
        ->assertHasErrors(['newAttachments.0']);
});

test('a user with edit_issues can delete an attachment from the issue show page', function () {
    Storage::fake('public');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $media = $issue->attachments()->first();

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteAttachment', $media->id);

    expect($issue->fresh()->attachments())->toHaveCount(0);
});

test('a user without edit_issues cannot delete an attachment', function () {
    Storage::fake('public');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $media = $issue->attachments()->first();

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteAttachment', $media->id)
        ->assertForbidden();

    expect($issue->fresh()->attachments())->toHaveCount(1);
});
