<?php

use App\Enums\IssueRelationType;
use App\Enums\MailNotificationOption;
use App\Enums\WebhookEvent;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Webhook;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\WebhookServer\CallWebhookJob;

function journalMailMember(Project $project, MailNotificationOption $preference, array $permissions = ['view_issues', 'edit_issues', 'manage_issue_relations']): User
{
    $user = User::factory()->create(['mail_notification' => $preference]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function journalMailIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('adding a relation mails watchers of both issues with the relation row', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $watcherA = journalMailMember($project, MailNotificationOption::All);
    $a = journalMailIssue($project);
    $b = journalMailIssue($project);
    $relation = IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => IssueRelationType::Blocks]);

    app(IssueService::class)->journalizeRelation($relation, added: true, actor: $actor);

    Notification::assertSentTo($watcherA, IssueNotification::class, fn (IssueNotification $n) => $n->issue->is($a));
    Notification::assertSentTo($watcherA, IssueNotification::class, fn (IssueNotification $n) => $n->issue->is($b));
    expect(Notification::sent($watcherA, IssueNotification::class))->toHaveCount(2);

    $notification = Notification::sent($watcherA, IssueNotification::class)->first(fn (IssueNotification $n) => $n->issue->is($a));
    $html = (new App\Mail\IssueNotificationMail($notification->issue, 'updated', $actor, $notification->journal))->render();

    expect($html)->toContain('ブロックする')->toContain('#'.$b->id);
});

test('removing a relation is mailed too', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $watcher = journalMailMember($project, MailNotificationOption::All);
    $a = journalMailIssue($project);
    $b = journalMailIssue($project);
    $relation = IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => IssueRelationType::Relates]);

    app(IssueService::class)->journalizeRelation($relation, added: false, actor: $actor);

    expect(Notification::sent($watcher, IssueNotification::class))->toHaveCount(2);
});

test('attachments added in one save produce a single mail listing every file', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $watcher = journalMailMember($project, MailNotificationOption::All);
    $issue = journalMailIssue($project);
    $one = $issue->addMediaFromString('a')->usingFileName('one.txt')->toMediaCollection('attachments');
    $two = $issue->addMediaFromString('b')->usingFileName('two.txt')->toMediaCollection('attachments');

    app(IssueService::class)->journalizeAttachments($issue, [$one, $two], added: true, actor: $actor);

    $sent = Notification::sent($watcher, IssueNotification::class);
    expect($sent)->toHaveCount(1);

    $html = (new App\Mail\IssueNotificationMail($issue, 'updated', $actor, $sent->first()->journal))->render();
    expect($html)->toContain('one.txt')->toContain('two.txt');
});

test('journalizing no attachments records and sends nothing', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $issue = journalMailIssue($project);

    app(IssueService::class)->journalizeAttachments($issue, [], added: true, actor: $actor);

    Notification::assertNothingSent();
    expect(App\Models\Journal::query()->where('issue_id', $issue->id)->count())->toBe(0);
});

test('editing an issue and uploading files through the form mails the attachment journal once', function () {
    Notification::fake();
    Illuminate\Support\Facades\Storage::fake('local');
    $project = Project::factory()->create();
    $editor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $watcher = journalMailMember($project, MailNotificationOption::All);
    $issue = journalMailIssue($project);
    $project->trackers()->attach($issue->tracker_id);

    Livewire::actingAs($editor)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set('newAttachments', [UploadedFile::fake()->create('spec.pdf', 10), UploadedFile::fake()->create('notes.txt', 1)])
        ->call('save')
        ->assertHasNoErrors();

    $attachmentMails = Notification::sent($watcher, IssueNotification::class)->filter(
        fn (IssueNotification $n) => $n->journal?->details->contains('property', 'attachment')
    );

    expect($attachmentMails)->toHaveCount(1)
        ->and($attachmentMails->first()->journal->details->where('property', 'attachment'))->toHaveCount(2);
});

test('the notifications for attachments and relations never fire the issue.updated webhook', function () {
    Queue::fake();
    Notification::fake();
    Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::IssueUpdated->value]]);
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $a = journalMailIssue($project);
    $b = journalMailIssue($project);
    $media = $a->addMediaFromString('x')->usingFileName('x.txt')->toMediaCollection('attachments');
    $relation = IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => IssueRelationType::Relates]);

    app(IssueService::class)->journalizeAttachments($a, [$media], added: true, actor: $actor);
    app(IssueService::class)->journalizeRelation($relation, added: true, actor: $actor);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('the users who cannot view the issue or opted out get no attachment or relation mail', function () {
    Notification::fake();
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $silent = journalMailMember($project, MailNotificationOption::None);
    $blind = journalMailMember($project, MailNotificationOption::All, permissions: []);
    $issue = journalMailIssue($project);
    $media = $issue->addMediaFromString('x')->usingFileName('x.txt')->toMediaCollection('attachments');

    app(IssueService::class)->journalizeAttachments($issue, [$media], added: true, actor: $actor);

    Notification::assertNotSentTo($silent, IssueNotification::class);
    Notification::assertNotSentTo($blind, IssueNotification::class);
});

test('a subject with a status change is not added to attachment or relation mails', function () {
    $project = Project::factory()->create();
    $actor = journalMailMember($project, MailNotificationOption::OnlyMyEvents);
    $issue = journalMailIssue($project, ['subject' => 'Plain subject']);
    $journal = App\Models\Journal::create(['issue_id' => $issue->id, 'user_id' => $actor->id, 'notes' => null]);
    $journal->details()->create(['property' => 'attachment', 'prop_key' => '1', 'old_value' => null, 'new_value' => 'f.txt']);

    $subject = (new App\Mail\IssueNotificationMail($issue->fresh(), 'updated', $actor, $journal->load('details')))->envelope()->subject;

    expect($subject)->toEndWith('] Plain subject');
});
