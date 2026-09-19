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
use App\Services\IncomingMailService;
use Livewire\Livewire;

function mailApiSetup(): User
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Enumeration::factory()->create(['is_default' => true]);
    Setting::set('incoming_mail_default_project_id', $project->id);
    Setting::set('incoming_mail_default_tracker_id', $tracker->id);
    Setting::set('incoming_mail_default_status_id', IssueStatus::factory()->create()->id);
    Setting::set('mail_handler_api_enabled', true);
    Setting::set('mail_handler_api_key', 'secret-key-123');
    $user = User::factory()->create(['email' => 'sender@example.com']);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));

    return $user;
}

function mailApiRaw(string $subject = 'Via web service', string $body = 'Please look.', string $from = 'sender@example.com'): string
{
    return "From: Sender <{$from}>\r\nTo: tracker@example.com\r\nSubject: {$subject}\r\nMessage-ID: <".uniqid().">@example.com\r\nDate: Sat, 20 Sep 2026 09:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
}

test('a posted raw mail with the right key creates an issue and answers 201', function () {
    $user = mailApiSetup();

    $this->post('/mail_handler', ['key' => 'secret-key-123', 'email' => mailApiRaw()])->assertCreated();

    $issue = Issue::query()->where('subject', 'Via web service')->firstOrFail();
    expect($issue->author_id)->toBe($user->id)->and($issue->description)->toContain('Please look.');
});

test('a wrong or missing key, or a disabled service, is refused with 403', function () {
    mailApiSetup();

    $this->post('/mail_handler', ['key' => 'wrong', 'email' => mailApiRaw()])->assertForbidden();
    $this->post('/mail_handler', ['email' => mailApiRaw()])->assertForbidden();

    Setting::set('mail_handler_api_enabled', false);
    $this->post('/mail_handler', ['key' => 'secret-key-123', 'email' => mailApiRaw()])->assertForbidden();
    expect(Issue::count())->toBe(0);
});

test('an empty configured key never matches an empty request key', function () {
    mailApiSetup();
    Setting::set('mail_handler_api_key', '');

    $this->post('/mail_handler', ['key' => '', 'email' => mailApiRaw()])->assertForbidden();
});

test('mail from an unknown sender is not accepted (422) and creates nothing', function () {
    mailApiSetup();

    $this->post('/mail_handler', ['key' => 'secret-key-123', 'email' => mailApiRaw(from: 'stranger@example.com')])->assertStatus(422);

    expect(Issue::count())->toBe(0);
});

test('a missing or garbage email body is a 422, not a server error', function () {
    mailApiSetup();

    $this->post('/mail_handler', ['key' => 'secret-key-123'])->assertStatus(422);
    $this->post('/mail_handler', ['key' => 'secret-key-123', 'email' => "\0\0not an email"])->assertStatus(422);
});

test('a plain GET is not routed and the endpoint needs no CSRF token', function () {
    mailApiSetup();

    $this->get('/mail_handler')->assertStatus(405);
    $this->post('/mail_handler', ['key' => 'secret-key-123', 'email' => mailApiRaw('No csrf needed')])->assertCreated();
});

test('body delimiters can be regular expressions when enabled', function () {
    Setting::set('mail_handler_body_delimiters', "^On .+ wrote:\n[unclosed");
    $service = app(IncomingMailService::class);
    $body = "Real text\nOn Sat, Alice wrote:\nquoted reply";

    expect($service->truncateBody($body))->toBe($body);

    Setting::set('mail_handler_enable_regex_delimiters', true);
    expect($service->truncateBody($body))->toBe('Real text');
});

test('excluded file names can be regular expressions when enabled, and a bad pattern matches nothing', function () {
    Setting::set('mail_handler_excluded_filenames', '^signature-\d+\.png$, [broken');
    $service = app(IncomingMailService::class);

    expect($service->filenameExcluded('signature-12.png'))->toBeFalse();

    Setting::set('mail_handler_enable_regex_excluded_filenames', true);
    expect($service->filenameExcluded('signature-12.png'))->toBeTrue()
        ->and($service->filenameExcluded('report.pdf'))->toBeFalse();
});

test('the settings page saves the web service switch, key and regex options, and can generate a key', function () {
    $admin = User::factory()->admin()->create();

    $page = Livewire::actingAs($admin)->test('settings.index')->call('generateMailHandlerApiKey');
    expect(strlen($page->get('mail_handler_api_key')))->toBe(40);

    $page->set('mail_handler_api_enabled', true)->set('mail_handler_enable_regex_delimiters', true)->set('mail_handler_enable_regex_excluded_filenames', true)->call('save')->assertHasNoErrors();

    expect(Setting::get('mail_handler_api_enabled'))->toBeTrue()
        ->and(Setting::get('mail_handler_enable_regex_delimiters'))->toBeTrue()
        ->and(strlen((string) Setting::get('mail_handler_api_key')))->toBe(40);
});
