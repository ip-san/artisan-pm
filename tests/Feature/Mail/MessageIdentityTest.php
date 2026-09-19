<?php

use App\Enums\MailNotificationOption;
use App\Mail\IssueNotificationMail;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IncomingMailService;
use App\Support\Mail\MessageIdentity;
use App\Support\Mail\ParsedIncomingMail;

/**
 * @return array{0: Project, 1: User, 2: Issue}
 */
function identityIssue(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = User::factory()->create(['email' => 'reply@example.com', 'mail_notification' => MailNotificationOption::All]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues', 'add_issues']])
    );
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $user->id,
    ]);

    return [$project, $user, $issue];
}

test('the message id follows the mail_from domain, then host_name, then the machine name', function () {
    expect(MessageIdentity::host())->toBe(gethostname().'.redmine');

    Setting::set('host_name', 'pm.example.com:8080/redmine');
    expect(MessageIdentity::host())->toBe('pm.example.com:8080');

    Setting::set('mail_from', 'Redmine <noreply@mail.example.org>');
    expect(MessageIdentity::host())->toBe('mail.example.org');
});

test('a creation mail is identified by the issue and an update mail by its journal, referencing the issue', function () {
    [, $recipient, $issue] = identityIssue();
    $actor = User::factory()->create();
    Setting::set('mail_from', 'noreply@example.com');
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $actor->id, 'notes' => 'update']);
    $stamp = fn ($model) => $model->created_at->copy()->utc()->format('YmdHis');

    $created = (new IssueNotificationMail($issue, 'created', $actor, null, $recipient->id))->headers();
    $updated = (new IssueNotificationMail($issue, 'updated', $actor, $journal, $recipient->id))->headers();

    expect($created->messageId)->toBe("redmine.issue-{$issue->id}.{$stamp($issue)}.{$recipient->id}@example.com")
        ->and($created->references)->toBe([])
        ->and($updated->messageId)->toBe("redmine.journal-{$journal->id}.{$stamp($journal)}.{$recipient->id}@example.com")
        ->and($updated->references)->toBe(["redmine.issue-{$issue->id}.{$stamp($issue)}.{$recipient->id}@example.com"]);
});

test('the notification hands the recipient to the mail', function () {
    [, $recipient, $issue] = identityIssue();

    $mail = (new IssueNotification($issue, 'created', User::factory()->create()))->toMail($recipient);

    expect($mail->recipientId)->toBe($recipient->id);
});

test('reply targets are found in In-Reply-To and References values', function () {
    expect(MessageIdentity::target(['<redmine.issue-42.20260101000000.7@example.com>']))->toBe(['issue', 42])
        ->and(MessageIdentity::target(['<other@x> <redmine.journal-9.20260101000000@example.com>']))->toBe(['journal', 9])
        ->and(MessageIdentity::target(['<redmine.issue-5.20260101000000.a1f@example.com>']))->toBe(['issue', 5])
        ->and(MessageIdentity::target(['<someone@else.example>', '']))->toBeNull()
        ->and(MessageIdentity::target([]))->toBeNull();
});

test('a reply whose In-Reply-To names the issue comments on it whatever the subject says', function () {
    [, , $issue] = identityIssue();
    $author = User::query()->where('email', 'reply@example.com')->firstOrFail();

    $result = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'Totally unrelated subject',
        body: 'Threaded answer',
        fromEmail: 'reply@example.com',
        replyHeaders: ["<redmine.issue-{$issue->id}.20260101000000.{$author->id}@example.com>"],
    ));

    expect($result?->id)->toBe($issue->id)
        ->and(Journal::query()->where('issue_id', $issue->id)->where('notes', 'Threaded answer')->exists())->toBeTrue()
        ->and(Issue::query()->count())->toBe(1);
});

test('a reply to a journal mail lands on that journal\'s issue', function () {
    [, , $issue] = identityIssue();
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => User::factory()->create()->id, 'notes' => 'first']);

    app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'Re: something',
        body: 'Answer to the journal mail',
        fromEmail: 'reply@example.com',
        replyHeaders: ["<redmine.issue-{$issue->id}.20260101000000@example.com>", "<redmine.journal-{$journal->id}.20260101000000@example.com>"],
    ));

    expect(Journal::query()->where('issue_id', $issue->id)->where('notes', 'Answer to the journal mail')->exists())->toBeTrue();
});

test('a header naming a missing journal or an unsupported object is ignored and creates nothing', function () {
    [, , $issue] = identityIssue();
    Setting::set('incoming_mail_default_project_id', $issue->project_id);

    $service = app(IncomingMailService::class);
    $missing = $service->createIssueFromMail(new ParsedIncomingMail('Re: x', 'body', 'reply@example.com', replyHeaders: ['<redmine.journal-99999.20260101000000@example.com>']));
    $unsupported = $service->createIssueFromMail(new ParsedIncomingMail('Re: x', 'body', 'reply@example.com', replyHeaders: ['<redmine.wiki_content-1.20260101000000@example.com>']));

    expect($missing)->toBeNull()->and($unsupported)->toBeNull()->and(Issue::query()->count())->toBe(1);
});

test('the subject match still works when no header points to us', function () {
    [, , $issue] = identityIssue();

    $result = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: "Re: [Project - Bug #{$issue->id}] Something",
        body: 'Subject-routed answer',
        fromEmail: 'reply@example.com',
        replyHeaders: ['<unrelated@mail.example>'],
    ));

    expect($result?->id)->toBe($issue->id);
});

test('the headers reach the message that is actually sent', function () {
    [, $recipient, $issue] = identityIssue();
    Setting::set('mail_from', 'noreply@example.com');

    $recipient->notify(new IssueNotification($issue, 'created', User::factory()->create()));

    $sent = Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($sent->getHeaders()->get('Message-ID')->getBodyAsString())->toStartWith("<redmine.issue-{$issue->id}.")->toEndWith(".{$recipient->id}@example.com>");
});
