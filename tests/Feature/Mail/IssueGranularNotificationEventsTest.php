<?php

use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function granularNotifiableMember(Project $project): User
{
    $user = User::factory()->create(['mail_notification' => MailNotificationOption::All]);
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int}
 */
function granularIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
}

test('a status change is mailed once issue_status_updated is enabled even without issue_updated', function () {
    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);
    $newStatus = IssueStatus::factory()->create();

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);

    Setting::set('notified_events', ['issue_status_updated']);
    Notification::fake();

    app(IssueService::class)->update($issue, ['status_id' => $newStatus->id], $author);

    Notification::assertSentTo($member, IssueNotification::class);
});

test('a plain field change does not notify under issue_status_updated alone', function () {
    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);

    Setting::set('notified_events', ['issue_status_updated']);
    Notification::fake();

    app(IssueService::class)->update($issue, ['subject' => 'Renamed'], $author);

    Notification::assertNotSentTo($member, IssueNotification::class);
});

test('an assignee change is mailed once issue_assigned_to_updated is enabled', function () {
    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $assignee = User::factory()->create();
    Member::factory()->for($project)->for($assignee)->create()->roles()->attach($role);

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);

    Setting::set('notified_events', ['issue_assigned_to_updated']);
    Notification::fake();

    app(IssueService::class)->update($issue, ['assigned_to_id' => $assignee->id], $author);

    Notification::assertSentTo($member, IssueNotification::class);
});

test('a priority change is mailed once issue_priority_updated is enabled', function () {
    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);
    $newPriority = Enumeration::factory()->create();

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);

    Setting::set('notified_events', ['issue_priority_updated']);
    Notification::fake();

    app(IssueService::class)->update($issue, ['priority_id' => $newPriority->id], $author);

    Notification::assertSentTo($member, IssueNotification::class);
});

test('a fixed version change is mailed once issue_fixed_version_updated is enabled', function () {
    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);
    $version = Version::factory()->for($project)->create();

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);

    Setting::set('notified_events', ['issue_fixed_version_updated']);
    Notification::fake();

    app(IssueService::class)->update($issue, ['fixed_version_id' => $version->id], $author);

    Notification::assertSentTo($member, IssueNotification::class);
});

test('an added attachment is mailed once issue_attachment_added is enabled', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);
    $media = $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    Setting::set('notified_events', ['issue_attachment_added']);
    Notification::fake();

    app(IssueService::class)->journalizeAttachment($issue, $media, added: true, actor: $author);

    Notification::assertSentTo($member, IssueNotification::class);
});

test('removing an attachment does not notify under issue_attachment_added', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);
    $media = $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    Setting::set('notified_events', ['issue_attachment_added']);
    Notification::fake();

    app(IssueService::class)->journalizeAttachment($issue, $media, added: false, actor: $author);

    Notification::assertNotSentTo($member, IssueNotification::class);
});

test('a comment is still mailed under issue_note_added alone, matching the existing behaviour', function () {
    $project = Project::factory()->create();
    $member = granularNotifiableMember($project);
    $author = granularNotifiableMember($project);

    $issue = app(IssueService::class)->create([...granularIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Issue'], $author);

    Setting::set('notified_events', ['issue_note_added']);
    Notification::fake();

    app(IssueService::class)->update($issue, [], $author, comment: 'A note');

    Notification::assertSentTo($member, IssueNotification::class);
});
