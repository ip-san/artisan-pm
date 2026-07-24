<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Services\IncomingMailService;
use App\Support\Mail\ParsedIncomingMail;

function incomingMailAssignableUser(Project $project, string $name, string $email): User
{
    $user = User::factory()->create(['name' => $name, 'email' => $email]);
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'assignable' => true]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

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

test('a subject shaped like "[... #123]" adds a comment to the existing issue instead of creating a new one', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $author = incomingMailAuthor($project, ['view_issues', 'edit_issues']);

    $mail = new ParsedIncomingMail(subject: "[{$project->identifier} #{$issue->id}] Re: something", body: 'Here is an update.', fromEmail: $author->email);

    $result = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($result->id)->toBe($issue->id)
        ->and(Issue::count())->toBe(1)
        ->and($issue->fresh()->journals()->latest()->first()->notes)->toBe('Here is an update.')
        ->and($issue->fresh()->journals()->latest()->first()->user_id)->toBe($author->id);
});

test('a reply to a non-existent issue is ignored', function () {
    $author = User::factory()->create(['email' => 'sender@example.com']);

    $mail = new ParsedIncomingMail(subject: '[project #999999] Re: something', body: 'Update.', fromEmail: $author->email);

    expect(app(IncomingMailService::class)->createIssueFromMail($mail))->toBeNull();
});

test('a reply from a sender without edit_issues on the issue project is ignored', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    incomingMailAuthor($project, ['view_issues']);

    $mail = new ParsedIncomingMail(subject: "[project #{$issue->id}] Re: something", body: 'Update.', fromEmail: 'sender@example.com');

    expect(app(IncomingMailService::class)->createIssueFromMail($mail))->toBeNull();
    expect($issue->fresh()->journals)->toBeEmpty();
});

test('a reply with attachments attaches them to the existing issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $author = incomingMailAuthor($project, ['view_issues', 'edit_issues']);

    $mail = new ParsedIncomingMail(
        subject: "[project #{$issue->id}] Re: something",
        body: 'See attached.',
        fromEmail: $author->email,
        attachments: [['filename' => 'screenshot.png', 'content' => 'fake image bytes']],
    );

    app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->fresh()->attachments())->toHaveCount(1)
        ->and($issue->fresh()->attachments()->first()->file_name)->toBe('screenshot.png');
});

test('an oversized attachment is skipped without losing the issue or the other attachments', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    config(['media-library.max_file_size' => 10]);

    $mail = new ParsedIncomingMail(
        subject: 'With one oversized attachment',
        body: '...',
        fromEmail: 'sender@example.com',
        attachments: [
            ['filename' => 'too-big.txt', 'content' => str_repeat('x', 100)],
            ['filename' => 'small.txt', 'content' => 'ok'],
        ],
    );

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue)->not->toBeNull()
        ->and($issue->attachments())->toHaveCount(1)
        ->and($issue->attachments()->first()->file_name)->toBe('small.txt');
});

test('resolveBody prefers the plain text part by default, falling back to a stripped html part when plain is empty', function () {
    $service = app(IncomingMailService::class);

    expect($service->resolveBody('Plain text.', '<p>HTML text.</p>'))->toBe('Plain text.')
        ->and($service->resolveBody('', '<p>HTML only.</p>'))->toBe('HTML only.');
});

test('resolveBody prefers a stripped html part when configured, falling back to plain text when html is empty', function () {
    Setting::set('mail_handler_preferred_body_part', 'html');
    $service = app(IncomingMailService::class);

    expect($service->resolveBody('Plain text.', '<p>HTML text.</p>'))->toBe('HTML text.')
        ->and($service->resolveBody('Plain fallback.', ''))->toBe('Plain fallback.');
});

test('truncateBody cuts the body at the first configured delimiter line', function () {
    Setting::set('mail_handler_body_delimiters', "-----Original Message-----\n> quoted reply");
    $service = app(IncomingMailService::class);

    $body = "The actual reply.\n\n-----Original Message-----\nOn Monday, someone wrote:\n> quoted text";

    expect($service->truncateBody($body))->toBe('The actual reply.');
});

test('truncateBody returns the body unchanged when no delimiter is configured or matched', function () {
    $service = app(IncomingMailService::class);

    expect($service->truncateBody("Just a reply.\nNo delimiter here."))->toBe("Just a reply.\nNo delimiter here.");
});

test('filenameExcluded matches configured glob patterns against the attachment filename', function () {
    Setting::set('mail_handler_excluded_filenames', '*.ics, winmail.dat');
    $service = app(IncomingMailService::class);

    expect($service->filenameExcluded('invite.ICS'))->toBeTrue()
        ->and($service->filenameExcluded('winmail.dat'))->toBeTrue()
        ->and($service->filenameExcluded('screenshot.png'))->toBeFalse();
});

test('filenameExcluded matches nothing when no pattern is configured', function () {
    expect(app(IncomingMailService::class)->filenameExcluded('anything.txt'))->toBeFalse();
});

test('a Status keyword line sets the issue status and is stripped from the description', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $closedStatus = IssueStatus::factory()->create(['name' => 'Closed']);
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(
        subject: 'Bug report',
        body: "It broke.\nStatus: Closed\nMore details.",
        fromEmail: 'sender@example.com',
    );

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->status_id)->toBe($closedStatus->id)
        ->and($issue->description)->toBe("It broke.\nMore details.");
});

test('keyword matching is case-insensitive on both the label and the value', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['name' => 'High']);
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(
        subject: 'Bug report',
        body: 'priority:  high',
        fromEmail: 'sender@example.com',
    );

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->priority_id)->toBe($priority->id);
});

test('an Assigned to keyword line resolves by email or by full name', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);
    $assignee = incomingMailAssignableUser($project, 'Alice Example', 'alice@example.com');

    $byEmail = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'By email', body: 'Assigned to: alice@example.com', fromEmail: 'sender@example.com',
    ));
    $byName = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'By name', body: 'Assigned to: Alice Example', fromEmail: 'sender@example.com',
    ));

    expect($byEmail->assigned_to_id)->toBe($assignee->id)
        ->and($byName->assigned_to_id)->toBe($assignee->id);
});

test('a Done ratio keyword line sets the progress percentage', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Progress update', body: 'Done ratio: 50', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->done_ratio)->toBe(50);
});

test('an out-of-range Done ratio value is ignored and left in the description', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Bad value', body: 'Done ratio: 150', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->done_ratio)->toBe(0)
        ->and($issue->description)->toBe('Done ratio: 150');
});

test('an unrecognized keyword value is left as plain text rather than silently dropped', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Unknown status', body: 'Status: NoSuchStatus', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->status_id)->toBe($status->id)
        ->and($issue->description)->toBe('Status: NoSuchStatus');
});

test('a Tracker keyword line resolves by name, scoped to trackers attached to the project', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $otherTracker = Tracker::factory()->create(['name' => 'Feature']);
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $unattached = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'Not attached', body: 'Tracker: Feature', fromEmail: 'sender@example.com',
    ));

    $project->trackers()->attach($otherTracker);

    $attached = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'Attached', body: 'Tracker: Feature', fromEmail: 'sender@example.com',
    ));

    expect($unattached->tracker_id)->toBe($tracker->id)
        ->and($unattached->description)->toBe('Tracker: Feature')
        ->and($attached->tracker_id)->toBe($otherTracker->id);
});

test('a Category keyword line resolves by name within the project', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Backend']);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Category: Backend', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->category_id)->toBe($category->id)
        ->and($issue->description)->toBe('');
});

test('a Fixed version keyword line resolves by name within the project', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);
    $version = Version::factory()->for($project)->create(['name' => '1.0']);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Fixed version: 1.0', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->fixed_version_id)->toBe($version->id)
        ->and($issue->description)->toBe('');
});

test('an unrecognized category name is left as plain text rather than silently dropped', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Category: NoSuchCategory', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->category_id)->toBeNull()
        ->and($issue->description)->toBe('Category: NoSuchCategory');
});

test('Start date and Due date keyword lines set those fields', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(
        subject: 'Bug report',
        body: "Start date: 2026-08-01\nDue date: 2026-08-15",
        fromEmail: 'sender@example.com',
    );

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->start_date->toDateString())->toBe('2026-08-01')
        ->and($issue->due_date->toDateString())->toBe('2026-08-15');
});

test('a malformed Due date value is left as plain text rather than silently dropped', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Due date: not-a-date', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->due_date)->toBeNull()
        ->and($issue->description)->toBe('Due date: not-a-date');
});

test('an Estimated hours keyword line sets the estimate', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Estimated hours: 3.5', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect((float) $issue->estimated_hours)->toBe(3.5);
});

test('an out-of-range Estimated hours value is ignored and left in the description', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Estimated hours: -5', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->estimated_hours)->toBeNull()
        ->and($issue->description)->toBe('Estimated hours: -5');
});

test('a Private keyword line sets is_private when the sender holds set_issues_private', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project, ['view_issues', 'add_issues', 'set_issues_private']);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Private: true', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->is_private)->toBeTrue();
});

test('a Private keyword line is ignored when the sender lacks set_issues_private', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: 'Private: true', fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->is_private)->toBeFalse()
        ->and($issue->description)->toBe('Private: true');
});

test('a keyword line on a reply updates the existing issue and is stripped from the comment', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $closedStatus = IssueStatus::factory()->create(['name' => 'Closed']);
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $author = incomingMailAuthor($project, ['view_issues', 'edit_issues']);

    $mail = new ParsedIncomingMail(
        subject: "[{$project->identifier} #{$issue->id}] Re: something",
        body: "Fixed now.\nStatus: Closed",
        fromEmail: $author->email,
    );

    $result = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($result->status_id)->toBe($closedStatus->id)
        ->and($issue->fresh()->journals()->latest()->first()->notes)->toBe('Fixed now.');
});

test('a Parent issue keyword line sets parent_id on a newly created issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);
    $parent = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: "Parent issue: #{$parent->id}", fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->parent_id)->toBe($parent->id)
        ->and($issue->description)->toBe('');
});

test('a Parent issue keyword line accepts a bare issue number without a # prefix', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);
    $parent = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: "Parent issue: {$parent->id}", fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->parent_id)->toBe($parent->id);
});

test('a Parent issue keyword referencing an issue in another project is ignored', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    configureIncomingMail($project, $tracker, $status);
    incomingMailAuthor($project);
    $otherProject->trackers()->attach($tracker);
    $foreignIssue = Issue::factory()->for($otherProject)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);

    $mail = new ParsedIncomingMail(subject: 'Bug report', body: "Parent issue: #{$foreignIssue->id}", fromEmail: 'sender@example.com');

    $issue = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->parent_id)->toBeNull()
        ->and($issue->description)->toBe("Parent issue: #{$foreignIssue->id}");
});

test('a Parent issue keyword on a reply updates the existing issue\'s parent', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $parent = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $author = incomingMailAuthor($project, ['view_issues', 'edit_issues']);

    $mail = new ParsedIncomingMail(
        subject: "[{$project->identifier} #{$issue->id}] Re: something",
        body: "Parent issue: #{$parent->id}",
        fromEmail: $author->email,
    );

    $result = app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($result->parent_id)->toBe($parent->id);
});

test('a Parent issue keyword attempting to set an issue as its own parent is ignored', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $author = incomingMailAuthor($project, ['view_issues', 'edit_issues']);

    $mail = new ParsedIncomingMail(
        subject: "[{$project->identifier} #{$issue->id}] Re: something",
        body: "Parent issue: #{$issue->id}",
        fromEmail: $author->email,
    );

    app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->fresh()->parent_id)->toBeNull();
});

test('a Parent issue keyword attempting to set a descendant as the parent is ignored', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $child = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => Enumeration::factory()->create()->id,
        'parent_id' => $issue->id,
    ]);
    $author = incomingMailAuthor($project, ['view_issues', 'edit_issues']);

    $mail = new ParsedIncomingMail(
        subject: "[{$project->identifier} #{$issue->id}] Re: something",
        body: "Parent issue: #{$child->id}",
        fromEmail: $author->email,
    );

    app(IncomingMailService::class)->createIssueFromMail($mail);

    expect($issue->fresh()->parent_id)->toBeNull();
});
