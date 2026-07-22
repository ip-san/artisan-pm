<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('uploading files on issue creation attaches them to the issue', function () {
    Storage::fake('local');

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

test('an attachment exceeding the configured attachment_max_size setting is rejected', function () {
    Storage::fake('local');

    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();
    Setting::set('attachment_max_size', 100);

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
        ->set('subject', 'Issue with a file too big for the configured limit')
        ->set('newAttachments', [UploadedFile::fake()->create('too-big.zip', 200)])
        ->call('save')
        ->assertHasErrors(['newAttachments.0']);
});

test('an attachment with a denied extension is rejected', function () {
    Storage::fake('local');

    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();
    Setting::set('attachment_extensions_denied', 'exe, sh');

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
        ->set('subject', 'Issue with a denied extension')
        ->set('newAttachments', [UploadedFile::fake()->create('script.exe', 10)])
        ->call('save')
        ->assertHasErrors(['newAttachments.0']);
});

test('an allow-list takes precedence, rejecting any extension not on it', function () {
    Storage::fake('local');

    Enumeration::factory()->create(['is_default' => true]);
    IssueStatus::factory()->create();
    Setting::set('attachment_extensions_allowed', 'png, jpg');

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
        ->set('subject', 'Issue with a non-allow-listed extension')
        ->set('newAttachments', [UploadedFile::fake()->create('notes.pdf', 10)])
        ->call('save')
        ->assertHasErrors(['newAttachments.0']);
});

test('an oversized attachment is rejected by validation', function () {
    Storage::fake('local');

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
    Storage::fake('local');

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
    Storage::fake('local');

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

test('a user with edit_issues can set an attachment description from the issue show page', function () {
    Storage::fake('local');

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
        ->set("attachmentDescriptions.{$media->id}", 'Screenshot of the crash dialog')
        ->call('updateAttachmentDescription', $media->id);

    expect($media->fresh()->getCustomProperty('description'))->toBe('Screenshot of the crash dialog');
});

test('setting an attachment description to blank clears it', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');
    $media->setCustomProperty('description', 'old description')->save();

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set("attachmentDescriptions.{$media->id}", '   ')
        ->call('updateAttachmentDescription', $media->id);

    expect($media->fresh()->getCustomProperty('description'))->toBeNull();
});

test('a user without edit_issues cannot set an attachment description', function () {
    Storage::fake('local');

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
        ->set("attachmentDescriptions.{$media->id}", 'sneaky')
        ->call('updateAttachmentDescription', $media->id)
        ->assertForbidden();

    expect($media->fresh()->getCustomProperty('description'))->toBeNull();
});

test('an existing attachment description is visible to a viewer without edit_issues', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');
    $media->setCustomProperty('description', 'Steps to reproduce')->save();

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee('Steps to reproduce');
});

test('a member of the owning project can download an attachment', function () {
    Storage::fake('local');

    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $media = $issue->attachments()->first();

    $this->actingAs($user)
        ->get(route('attachments.show', $media))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=notes.txt');
});

test('a non-member cannot download an attachment from a private project', function () {
    Storage::fake('local');

    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $outsider = User::factory()->create();
    $media = $issue->attachments()->first();

    $this->actingAs($outsider)
        ->get(route('attachments.show', $media))
        ->assertForbidden();
});

test('downloading an attachment increments its download count', function () {
    Storage::fake('local');

    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $media = $issue->attachments()->first();
    expect($media->getCustomProperty('download_count', 0))->toBe(0);

    $this->actingAs($user)->get(route('attachments.show', $media));
    $this->actingAs($user)->get(route('attachments.show', $media));

    expect($media->fresh()->getCustomProperty('download_count'))->toBe(2);
});

test('a guest is redirected to login when trying to download an attachment', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $media = $issue->attachments()->first();

    $this->get(route('attachments.show', $media))->assertRedirect(route('login'));
});

test('attaching a file while editing journals the addition; creation does not', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id]);

    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('newAttachments', [UploadedFile::fake()->create('spec.pdf', 100)])
        ->call('save');

    $media = $issue->fresh()->attachments()->first();
    $detail = $issue->journals()->latest('id')->firstOrFail()->details()->firstOrFail();

    expect($detail->property)->toBe('attachment')
        ->and($detail->prop_key)->toBe((string) $media->id)
        ->and($detail->new_value)->toBe('spec.pdf')
        ->and($detail->old_value)->toBeNull();
});

test('creating an issue with attachments produces no attachment journal', function () {
    Storage::fake('local');

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
        ->set('subject', 'Created with a file')
        ->set('newAttachments', [UploadedFile::fake()->create('initial.txt', 10)])
        ->call('save');

    $issue = Issue::where('subject', 'Created with a file')->firstOrFail();

    expect($issue->journals()->count())->toBe(0);
});

test('deleting an attachment journals the removal with the filename as old value', function () {
    Storage::fake('local');

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

    $detail = $issue->journals()->latest('id')->firstOrFail()->details()->firstOrFail();

    expect($detail->property)->toBe('attachment')
        ->and($detail->prop_key)->toBe((string) $media->id)
        ->and($detail->old_value)->toBe('notes.txt')
        ->and($detail->new_value)->toBeNull();
});
