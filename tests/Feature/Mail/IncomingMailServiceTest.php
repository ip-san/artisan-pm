<?php

use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IncomingMailService;
use App\Support\Mail\ParsedIncomingMail;

function configureIncomingMail(Project $project, Tracker $tracker, IssueStatus $status): void
{
    Enumeration::factory()->create(['is_default' => true]);
    $project->trackers()->attach($tracker);

    Setting::set('incoming_mail_default_project_id', $project->id);
    Setting::set('incoming_mail_default_tracker_id', $tracker->id);
    Setting::set('incoming_mail_default_status_id', $status->id);
}

function incomingMailAuthor(Project $project, array $permissions = ['view_issues', 'add_issues']): User
{
    $user = User::factory()->create(['email' => 'sender@example.com']);
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('an email from a known, authorized sender creates an issue in the default project', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    $author = incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Something is broken', body: 'Please fix it.', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue)->not->toBeNull()
        ->and($issue->subject)->toBe('Something is broken')
        ->and($issue->description)->toBe('Please fix it.')
        ->and($issue->project_id)->toBe($project->id)
        ->and($issue->tracker_id)->toBe($tracker->id)
        ->and($issue->status_id)->toBe($status->id)
        ->and($issue->author_id)->toBe($author->id);
});

test('a [project-identifier] subject prefix targets that project and is stripped from the subject', function () {
    $defaultProject = Project::factory()->create();
    $targetProject = Project::factory()->create(['identifier' => 'target-project']);
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($defaultProject, $tracker, $status);
    $targetProject->trackers()->attach($tracker);
    $author = incomingMailAuthor($targetProject);

    $mail = new ParsedIncomingMail(subject: '[target-project] Bug report', body: 'Details.', fromEmail: $author->email);

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->project_id)->toBe($targetProject->id)
        ->and($issue->subject)->toBe('Bug report');
});

test('an email from an address matching no local user is ignored', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);

    $mail = new ParsedIncomingMail(subject: 'Anonymous report', body: '...', fromEmail: 'nobody@example.com');

    expect(app(IncomingMailService::class)->createIssueFromMail($mail))->toBeNull();
});

test('an email from a known sender without add_issues permission is ignored', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project, ['view_issues']);

    $mail = new ParsedIncomingMail(subject: 'No permission', body: '...', fromEmail: 'sender@example.com');

    expect(app(IncomingMailService::class)->createIssueFromMail($mail))->toBeNull();
});

test('an email is ignored when no default project is configured and the subject has no project prefix', function () {
    $author = User::factory()->create(['email' => 'sender@example.com']);

    $mail = new ParsedIncomingMail(subject: 'Untargeted', body: '...', fromEmail: $author->email);

    expect(app(IncomingMailService::class)->createIssueFromMail($mail))->toBeNull();
});

test('an email is ignored when the default tracker is not attached to the resolved project', function () {
    $project = Project::factory()->create();
    $unattachedTracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    Setting::set('incoming_mail_default_project_id', $project->id);
    Setting::set('incoming_mail_default_tracker_id', $unattachedTracker->id);
    Setting::set('incoming_mail_default_status_id', $status->id);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Wrong tracker', body: '...', fromEmail: 'sender@example.com');

    expect(app(IncomingMailService::class)->createIssueFromMail($mail))->toBeNull();
});

test('an email with attachments attaches them to the created issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(
        subject: 'With attachment',
        body: '...',
        fromEmail: 'sender@example.com',
        attachments: [['filename' => 'log.txt', 'content' => 'error log contents']],
    );

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->attachments())->toHaveCount(1)
        ->and($issue->attachments()->first()->file_name)->toBe('log.txt');
});

test('fetchAndProcess does nothing when incoming mail is disabled', function () {
    Setting::set('incoming_mail_enabled', false);

    expect(app(IncomingMailService::class)->fetchAndProcess())->toBe(0);
});

test('fetchAndProcess does nothing when no mailbox host is configured', function () {
    Setting::set('incoming_mail_enabled', true);
    config(['imap.accounts.default.host' => '']);

    expect(app(IncomingMailService::class)->fetchAndProcess())->toBe(0);
});

test('a blank subject falls back to a placeholder', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: '', body: '...', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->subject)->toBe('(no subject)');
});

test('a subject longer than the column limit is truncated rather than failing', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: str_repeat('a', 300), body: '...', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue)->not->toBeNull()
        ->and(mb_strlen($issue->subject))->toBe(255);
});
