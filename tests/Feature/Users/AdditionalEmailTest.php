<?php

use App\Models\EmailAddress;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Rules\UniqueUserValueIgnoringCase;
use App\Services\AccountDeletionService;
use App\Services\IncomingMailService;
use App\Services\IssueService;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('a user can add, mute and remove additional addresses on the profile', function () {
    $user = User::factory()->create(['email' => 'main@example.com']);

    $page = Livewire::actingAs($user)->test('profile.index')
        ->set('newAdditionalEmail', 'second@example.com')->call('addEmail')->assertHasNoErrors()
        ->assertSee('second@example.com')->assertSee('data-additional-emails', false);
    $address = $user->additionalEmails()->firstOrFail();

    $page->call('toggleEmailNotify', $address->id);
    expect($address->fresh()->notify)->toBeFalse();

    $page->call('removeEmail', $address->id);
    expect($user->additionalEmails()->count())->toBe(0);
});

test('an address already used anywhere is refused', function () {
    $user = User::factory()->create(['email' => 'main@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    EmailAddress::factory()->for($other)->create(['address' => 'taken@example.com']);
    $user->additionalEmails()->create(['address' => 'mine@example.com']);

    foreach (['other@example.com', 'OTHER@example.com', 'taken@example.com', 'main@example.com', 'mine@example.com'] as $address) {
        Livewire::actingAs($user)->test('profile.index')->set('newAdditionalEmail', $address)->call('addEmail')->assertHasErrors(['newAdditionalEmail']);
    }

    expect($user->additionalEmails()->count())->toBe(1);
});

test('the number of additional addresses is capped by max_additional_emails', function () {
    Setting::set('max_additional_emails', 1);
    $user = User::factory()->create();
    $page = Livewire::actingAs($user)->test('profile.index');

    $page->set('newAdditionalEmail', 'one@example.com')->call('addEmail')->assertHasNoErrors();
    $page->set('newAdditionalEmail', 'two@example.com')->call('addEmail')->assertHasErrors(['newAdditionalEmail']);

    expect($user->additionalEmails()->count())->toBe(1);
});

test('the domain policy applies to additional addresses too', function () {
    Setting::set('email_domains_denied', 'blocked.test');
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->set('newAdditionalEmail', 'someone@blocked.test')->call('addEmail')->assertHasErrors(['newAdditionalEmail']);
});

test('a malformed address is rejected', function () {
    Livewire::actingAs(User::factory()->create())->test('profile.index')->set('newAdditionalEmail', 'not-an-email')->call('addEmail')->assertHasErrors(['newAdditionalEmail']);
});

test('another user cannot remove or mute an address that is not theirs', function () {
    $owner = User::factory()->create();
    $address = EmailAddress::factory()->for($owner)->create();
    $intruder = User::factory()->create();

    Livewire::actingAs($intruder)->test('profile.index')->call('removeEmail', $address->id);
    expect($address->fresh())->not->toBeNull();

    Livewire::actingAs($intruder)->test('profile.index')->call('toggleEmailNotify', $address->id)->assertNotFound();
});

test('a primary email cannot duplicate somebody else\'s additional address', function () {
    $owner = User::factory()->create();
    EmailAddress::factory()->for($owner)->create(['address' => 'shared@example.com']);
    $rule = new UniqueUserValueIgnoringCase('email');

    $failed = false;
    $rule->validate('email', 'Shared@Example.com', function () use (&$failed) {
        $failed = true;

        return new class
        {
            public function translate(): void {}
        };
    });

    expect($failed)->toBeTrue();
});

test('notification mail goes to the primary and every address left switched on', function () {
    $user = User::factory()->create(['email' => 'main@example.com']);
    EmailAddress::factory()->for($user)->create(['address' => 'on@example.com', 'notify' => true]);
    EmailAddress::factory()->for($user)->create(['address' => 'off@example.com', 'notify' => false]);

    expect($user->routeNotificationForMail())->toBe(['main@example.com', 'on@example.com']);
});

test('an issue notification mail is addressed to all of the user\'s addresses', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $author = User::factory()->create();
    $watcher = User::factory()->create(['email' => 'watcher@example.com']);
    EmailAddress::factory()->for($watcher)->create(['address' => 'watcher2@example.com']);
    Member::factory()->for($project)->for($watcher)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    Member::factory()->for($project)->for($author)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));
    Setting::set('notified_events', ['issue_added']);
    $watcher->update(['mail_notification' => 'all']);
    $sent = null;
    Notification::fake();

    app(IssueService::class)->create([
        'project_id' => $project->id, 'tracker_id' => $project->trackers->first()->id,
        'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id, 'subject' => 'Hello',
    ], $author);

    Notification::assertSentTo($watcher, IssueNotification::class, function (IssueNotification $notification) use ($watcher, &$sent) {
        $sent = $notification->toMail($watcher);

        return true;
    });
    expect(collect($sent->to)->pluck('address')->all())->toBe(['watcher@example.com', 'watcher2@example.com']);
});

test('an incoming mail from an additional address is matched to its user', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    Enumeration::factory()->create(['is_default' => true]);
    Setting::set('incoming_mail_default_project_id', $project->id);
    Setting::set('incoming_mail_default_tracker_id', $tracker->id);
    Setting::set('incoming_mail_default_status_id', IssueStatus::factory()->create()->id);
    $user = User::factory()->create(['email' => 'main@example.com']);
    EmailAddress::factory()->for($user)->create(['address' => 'Home@Example.com']);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));

    $issue = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(subject: 'From home', body: 'text', fromEmail: 'home@example.com'));

    expect($issue)->not->toBeNull()->and($issue->author_id)->toBe($user->id);
});

test('deleting an account removes its additional addresses', function () {
    $user = User::factory()->create();
    EmailAddress::factory()->for($user)->create();

    app(AccountDeletionService::class)->delete($user);

    expect(EmailAddress::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('the settings page saves the limit and rejects a negative one', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('max_additional_emails', 3)->call('save')->assertHasNoErrors();
    expect(Setting::get('max_additional_emails'))->toBe(3);

    Livewire::actingAs($admin)->test('settings.index')->set('max_additional_emails', -1)->call('save')->assertHasErrors(['max_additional_emails']);
});
