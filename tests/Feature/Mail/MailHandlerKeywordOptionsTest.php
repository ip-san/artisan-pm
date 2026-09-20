<?php

use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IncomingMailService;
use App\Support\Mail\ParsedIncomingMail;

/**
 * @return array{0: Project, 1: Tracker, 2: User}
 */
function keywordOptionsSetup(): array
{
    $project = Project::factory()->create(['identifier' => 'default-proj']);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Enumeration::factory()->create(['is_default' => true]);
    Setting::set('incoming_mail_default_project_id', $project->id);
    Setting::set('incoming_mail_default_tracker_id', $tracker->id);
    Setting::set('incoming_mail_default_status_id', IssueStatus::factory()->create()->id);

    $author = User::factory()->create(['email' => 'sender@example.com']);
    Member::factory()->for($project)->for($author)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'edit_issues']]));

    return [$project, $tracker, $author];
}

function keywordOptionsMail(string $body, string $subject = 'Hello', array $recipients = []): ParsedIncomingMail
{
    return new ParsedIncomingMail(subject: $subject, body: $body, fromEmail: 'sender@example.com', recipients: $recipients);
}

function keywordOptionsField(Tracker $tracker, array $attributes = [], bool $list = false, ?Project $project = null): CustomField
{
    $field = $list ? CustomField::factory()->list(['Red', 'Green']) : CustomField::factory();
    $field = $field->create($attributes);
    $field->trackers()->attach($tracker);

    return $field;
}

test('every keyword is honored while allow_override is the default all', function () {
    [$project] = keywordOptionsSetup();
    IssueStatus::factory()->create(['name' => 'Feedback']);

    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Status: Feedback\nDone ratio: 40\nbody"));

    expect($issue->status->name)->toBe('Feedback')->and($issue->done_ratio)->toBe(40);
});

test('only the listed keywords are honored, the others stay in the body', function () {
    keywordOptionsSetup();
    $feedback = IssueStatus::factory()->create(['name' => 'Feedback']);
    Setting::set('mail_handler_allow_override', 'Status, done ratio');

    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Status: Feedback\nEstimated hours: 3\nDone ratio: 20"));

    expect($issue->status_id)->toBe($feedback->id)
        ->and($issue->done_ratio)->toBe(20)
        ->and($issue->estimated_hours)->toBeNull()
        ->and($issue->description)->toBe('Estimated hours: 3');
});

test('an empty allow_override list turns the keywords off', function () {
    keywordOptionsSetup();
    IssueStatus::factory()->create(['name' => 'Feedback']);
    Setting::set('mail_handler_allow_override', '');

    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Status: Feedback\ntext"));

    expect($issue->status->name)->not->toBe('Feedback')->and($issue->description)->toBe("Status: Feedback\ntext");
});

test('allow_override applies to a reply as well', function () {
    [$project, $tracker, $author] = keywordOptionsSetup();
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'done_ratio' => 0]);
    Setting::set('mail_handler_allow_override', 'status');

    app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Done ratio: 60\nreply text", "Re: [Issue #{$issue->id}]"));

    expect($issue->fresh()->done_ratio)->toBe(0);
});

test('a custom field keyword sets the field and leaves the body', function () {
    [, $tracker] = keywordOptionsSetup();
    $ticket = keywordOptionsField($tracker, ['name' => 'Ticket ref']);
    $color = keywordOptionsField($tracker, ['name' => 'Color'], list: true);

    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Ticket ref: REF-7\ncolor: green\nreal body"));

    expect($issue->description)->toBe('real body')
        ->and($issue->customValue($ticket))->toBe('REF-7')
        ->and($issue->customValue($color))->toBe('Green');
});

test('an invalid custom field value or one the sender may not edit is ignored', function () {
    [, $tracker] = keywordOptionsSetup();
    $color = keywordOptionsField($tracker, ['name' => 'Color'], list: true);
    $locked = keywordOptionsField($tracker, ['name' => 'Locked', 'editable' => false]);

    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Color: Purple\nLocked: x\nbody"));

    expect($issue->customValue($color))->toBeNull()
        ->and($issue->customValue($locked))->toBeNull()
        ->and($issue->description)->toBe("Color: Purple\nLocked: x\nbody");
});

test('a custom field keyword in a reply is recorded on the issue', function () {
    [$project, $tracker] = keywordOptionsSetup();
    $ticket = keywordOptionsField($tracker, ['name' => 'Ticket ref']);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Ticket ref: REF-9\nthanks", "Re: [Issue #{$issue->id}]"));

    expect($issue->fresh()->customValue($ticket))->toBe('REF-9');
});

test('the project comes from a plus address when project_from_subaddress is set', function () {
    [$default, $tracker, $author] = keywordOptionsSetup();
    $support = Project::factory()->create(['identifier' => 'support']);
    $support->trackers()->attach($tracker);
    Member::factory()->for($support)->for($author)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));
    Setting::set('mail_handler_project_from_subaddress', 'Redmine@Example.net');

    $viaSubaddress = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'Hello', ['other@example.com', 'redmine+SUPPORT@example.net']));
    $noMatch = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'Hello', ['redmine+unknown@example.net', 'redmine+support@elsewhere.org']));

    expect($viaSubaddress->project_id)->toBe($support->id)
        ->and($noMatch->project_id)->toBe($default->id);
});

test('a plus address is ignored while the setting is empty', function () {
    [$default] = keywordOptionsSetup();
    Project::factory()->create(['identifier' => 'support']);

    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'Hello', ['redmine+support@example.net']));

    expect($issue->project_id)->toBe($default->id);
});

test('the settings form saves the override list and the subaddress', function () {
    $admin = User::factory()->admin()->create();

    Livewire\Livewire::actingAs($admin)->test('settings.index')
        ->set('mail_handler_allow_override', 'status, priority')
        ->set('mail_handler_project_from_subaddress', 'redmine@example.net')
        ->call('save')->assertHasNoErrors();

    expect(Setting::get('mail_handler_allow_override'))->toBe('status, priority')
        ->and(Setting::get('mail_handler_project_from_subaddress'))->toBe('redmine@example.net');

    Livewire\Livewire::actingAs($admin)->test('settings.index')
        ->set('mail_handler_project_from_subaddress', 'no-at-sign')
        ->call('save')->assertHasErrors('mail_handler_project_from_subaddress');
});

test('a received mail notifies the members unless no_notification is on', function () {
    [$project, $tracker] = keywordOptionsSetup();
    $bystander = User::factory()->create(['mail_notification' => App\Enums\MailNotificationOption::All]);
    Member::factory()->for($project)->for($bystander)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    Illuminate\Support\Facades\Notification::fake();
    app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'First'));
    Illuminate\Support\Facades\Notification::assertSentTo($bystander, App\Notifications\IssueNotification::class);

    Setting::set('mail_handler_no_notification', true);
    Illuminate\Support\Facades\Notification::fake();
    $issue = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'Second'));
    Illuminate\Support\Facades\Notification::assertNothingSent();

    // A reply is silent too, and the suppression ends with the mail.
    app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('a reply', "Re: [Issue #{$issue->id}]"));
    Illuminate\Support\Facades\Notification::assertNothingSent();
    expect(App\Support\Mail\MailSuppression::active())->toBeFalse();
});

test('the settings form saves no_notification', function () {
    Livewire\Livewire::actingAs(User::factory()->admin()->create())->test('settings.index')
        ->set('mail_handler_no_notification', true)->call('save')->assertHasNoErrors();

    expect(Setting::get('mail_handler_no_notification'))->toBeTrue();
});

test('a mail issue gets no start date unless the API and mail switch is on', function () {
    keywordOptionsSetup();

    $off = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'Off'));
    expect($off->start_date)->toBeNull();

    Setting::set('default_issue_start_date_for_api_and_mail', true);
    $on = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'On'));
    $explicit = app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail("Start date: 2026-01-02\nbody", 'Explicit'));

    expect($on->start_date->toDateString())->toBe(today()->toDateString())
        ->and($explicit->start_date->toDateString())->toBe('2026-01-02');

    Setting::set('default_issue_start_date_to_creation_date', false);
    expect(app(IncomingMailService::class)->createIssueFromMail(keywordOptionsMail('body', 'Base off'))->start_date)->toBeNull();
});
