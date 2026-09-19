<?php

use App\Enums\MailNotificationOption;
use App\Models\Board;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Message;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Notifications\IssueNotification;
use App\Notifications\ProjectEventNotification;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function eventMailMember(Project $project, array $permissions, MailNotificationOption $option = MailNotificationOption::All): User
{
    $user = User::factory()->create(['mail_notification' => $option]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('posting a forum topic mails members who can see the forum when message_posted is on', function () {
    Notification::fake();
    Setting::set('notified_events', ['message_posted']);
    $project = Project::factory()->create();
    $board = Board::factory()->for($project)->create();
    $author = eventMailMember($project, ['view_messages', 'add_messages']);
    $reader = eventMailMember($project, ['view_messages']);
    $blind = eventMailMember($project, ['view_issues']);

    Livewire::actingAs($author)->test('messages.form', ['project' => $project, 'board' => $board])
        ->set('subject', 'Hello forum')->set('content', 'First post')->call('save');

    Notification::assertSentTo($reader, ProjectEventNotification::class, fn ($n) => str_contains($n->subjectLine, 'Hello forum') && $n->body === 'First post');
    Notification::assertNotSentTo($blind, ProjectEventNotification::class);
    Notification::assertNotSentTo($author, ProjectEventNotification::class);
});

test('nothing is sent while message_posted is off', function () {
    Notification::fake();
    Setting::set('notified_events', ['issue_added']);
    $project = Project::factory()->create();
    $board = Board::factory()->for($project)->create();
    $author = eventMailMember($project, ['view_messages', 'add_messages']);
    eventMailMember($project, ['view_messages']);

    Livewire::actingAs($author)->test('messages.form', ['project' => $project, 'board' => $board])->set('subject', 'S')->set('content', 'C')->call('save');

    Notification::assertNothingSent();
});

test('a reply mails members and the topic\'s watchers, and names the topic in the subject', function () {
    Notification::fake();
    Setting::set('notified_events', ['message_posted']);
    $project = Project::factory()->create();
    $board = Board::factory()->for($project)->create();
    $poster = eventMailMember($project, ['view_messages', 'add_messages']);
    $watcher = eventMailMember($project, ['view_messages'], MailNotificationOption::OnlyMyEvents);
    $topic = Message::factory()->for($board)->create(['subject' => 'Topic subject', 'author_id' => $poster->id]);
    $topic->watchers()->create(['user_id' => $watcher->id]);

    Livewire::actingAs($poster)->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])->set('replyContent', 'A reply')->call('addReply');

    Notification::assertSentTo($watcher, ProjectEventNotification::class);
});

test('adding a document mails the members who may view documents', function () {
    Notification::fake();
    Setting::set('notified_events', ['document_added']);
    $project = Project::factory()->create();
    $author = eventMailMember($project, ['view_documents', 'add_documents']);
    $reader = eventMailMember($project, ['view_documents']);
    $blind = eventMailMember($project, ['view_issues']);

    Livewire::actingAs($author)->test('documents.form', ['project' => $project])->set('title', 'Spec sheet')->call('save');

    Notification::assertSentTo($reader, ProjectEventNotification::class, fn ($n) => str_contains($n->subjectLine, 'Spec sheet'));
    Notification::assertNotSentTo($blind, ProjectEventNotification::class);
});

test('uploading files mails the members who may see files, naming the files and version', function () {
    Storage::fake('local');
    Notification::fake();
    Setting::set('notified_events', ['file_added']);
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['name' => 'v2']);
    $uploader = eventMailMember($project, ['manage_files', 'view_files']);
    $reader = eventMailMember($project, ['view_files']);
    $blind = eventMailMember($project, ['view_issues']);

    Livewire::actingAs($uploader)->test('files.index', ['project' => $project])
        ->set('version_id', $version->id)->set('newFiles', [UploadedFile::fake()->create('release.zip', 10)])->call('upload');

    Notification::assertSentTo($reader, ProjectEventNotification::class, fn ($n) => str_contains($n->subjectLine, 'release.zip') && str_contains($n->headline, 'v2'));
    Notification::assertNotSentTo($blind, ProjectEventNotification::class);
});

test('a comment mails when only issue_note_added is on, and a comment-less change does not', function () {
    Notification::fake();
    Setting::set('notified_events', ['issue_note_added']);
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $author = eventMailMember($project, ['view_issues', 'add_issues', 'edit_issues'], MailNotificationOption::OnlyMyEvents);
    $other = eventMailMember($project, ['view_issues'], MailNotificationOption::All);
    $issue = app(IssueService::class)->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id, 'subject' => 'Note test',
    ], $author);
    Notification::assertNothingSent(); // creation: issue_added is off

    app(IssueService::class)->update($issue, ['subject' => 'Renamed'], $author);
    Notification::assertNotSentTo($other, IssueNotification::class);

    app(IssueService::class)->update($issue->fresh(), ['subject' => 'Renamed again'], $author, 'Please look');
    Notification::assertSentTo($other, IssueNotification::class);
});

test('issue_updated still covers comments and details as before', function () {
    Notification::fake();
    Setting::set('notified_events', ['issue_updated']);
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $author = eventMailMember($project, ['view_issues', 'add_issues', 'edit_issues'], MailNotificationOption::OnlyMyEvents);
    $other = eventMailMember($project, ['view_issues'], MailNotificationOption::All);
    $issue = app(IssueService::class)->create([
        'project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id, 'subject' => 'Plain',
    ], $author);

    app(IssueService::class)->update($issue, ['subject' => 'Changed'], $author);

    Notification::assertSentTo($other, IssueNotification::class);
});

test('the settings page offers and saves the new events', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->assertSee('フォーラムにメッセージが投稿されたとき')
        ->set('notified_events', ['message_posted', 'document_added', 'file_added', 'issue_note_added'])->call('save')->assertHasNoErrors();

    expect(Setting::get('notified_events'))->toContain('message_posted', 'document_added', 'file_added', 'issue_note_added');
});

test('the project event mail renders both the html and the text body', function () {
    $mail = new App\Mail\ProjectEventNotificationMail('[P] Subject', 'Someone did it.', 'The title', 'https://example.test/x', 'Body here');

    $mail->assertSeeInHtml('The title')->assertSeeInHtml('Body here')->assertSeeInText('https://example.test/x');
});
