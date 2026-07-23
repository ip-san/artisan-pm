<?php

use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can update settings', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('app_title', 'My PM Tool')
        ->set('default_issues_per_page', 50)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('app_title'))->toBe('My PM Tool')
        ->and(Setting::get('default_issues_per_page'))->toBe(50);
});

test('a non-admin cannot access the settings form', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('settings.index')->assertForbidden();
});

test('the per-page setting must be within a sane range', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('default_issues_per_page', 1)
        ->call('save')
        ->assertHasErrors(['default_issues_per_page']);
});

test('an admin can configure incoming mail settings', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $status = IssueStatus::factory()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('incoming_mail_enabled', true)
        ->set('incoming_mail_default_project_id', $project->id)
        ->set('incoming_mail_default_tracker_id', $tracker->id)
        ->set('incoming_mail_default_status_id', $status->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('incoming_mail_enabled'))->toBeTrue()
        ->and(Setting::get('incoming_mail_default_project_id'))->toBe($project->id)
        ->and(Setting::get('incoming_mail_default_tracker_id'))->toBe($tracker->id)
        ->and(Setting::get('incoming_mail_default_status_id'))->toBe($status->id);
});

test('an admin can configure attachment size and extension limits', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('attachment_max_size', 2048)
        ->set('attachment_extensions_allowed', 'png, jpg')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('attachment_max_size'))->toBe(2048)
        ->and(Setting::get('attachment_extensions_allowed'))->toBe('png, jpg');
});

test('an admin can configure default project visibility, modules, and trackers', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('default_projects_public', false)
        ->set('default_projects_modules', [ProjectModuleKey::IssueTracking->value])
        ->set('default_projects_tracker_ids', [$tracker->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('default_projects_public'))->toBeFalse()
        ->and(Setting::get('default_projects_modules'))->toBe([ProjectModuleKey::IssueTracking->value])
        ->and(Setting::get('default_projects_tracker_ids'))->toBe([$tracker->id]);
});

test('an admin can configure mail handler body/attachment filtering and repository autofetch', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('mail_handler_body_delimiters', "-----Original Message-----\n> wrote:")
        ->set('mail_handler_excluded_filenames', '*.ics, winmail.dat')
        ->set('mail_handler_preferred_body_part', 'html')
        ->set('autofetch_changesets', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('mail_handler_body_delimiters'))->toBe("-----Original Message-----\n> wrote:")
        ->and(Setting::get('mail_handler_excluded_filenames'))->toBe('*.ics, winmail.dat')
        ->and(Setting::get('mail_handler_preferred_body_part'))->toBe('html')
        ->and(Setting::get('autofetch_changesets'))->toBeTrue();
});

test('an admin can enable commit message time logging and choose its activity', function () {
    $admin = User::factory()->admin()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('commit_logtime_enabled', true)
        ->set('commit_logtime_activity_id', $activity->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('commit_logtime_enabled'))->toBeTrue()
        ->and(Setting::get('commit_logtime_activity_id'))->toBe($activity->id);
});

test('commit_logtime_activity_id must reference a real enumeration', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('commit_logtime_activity_id', 999999)
        ->call('save')
        ->assertHasErrors(['commit_logtime_activity_id']);
});

test('mail_handler_preferred_body_part must be plain or html', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('mail_handler_preferred_body_part', 'rtf')
        ->call('save')
        ->assertHasErrors(['mail_handler_preferred_body_part']);
});

test('attachment_max_size cannot exceed the underlying media-library cap', function () {
    $admin = User::factory()->admin()->create();
    $hardCapKb = intdiv((int) config('media-library.max_file_size'), 1024);

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('attachment_max_size', $hardCapKb + 1)
        ->call('save')
        ->assertHasErrors(['attachment_max_size']);
});

test('an admin can configure the default issue due date offset', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('default_issue_due_date_offset', 14)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('default_issue_due_date_offset'))->toBe(14);
});

test('default_issue_due_date_offset rejects a negative number of days', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('default_issue_due_date_offset', -1)
        ->call('save')
        ->assertHasErrors(['default_issue_due_date_offset']);
});

test('default_issue_due_date_offset can be left blank to disable the default', function () {
    $admin = User::factory()->admin()->create();
    Setting::set('default_issue_due_date_offset', 7);

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('default_issue_due_date_offset', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('default_issue_due_date_offset'))->toBeNull();
});

test('an admin can configure the self-registration email domain allow/deny lists', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('email_domains_allowed', 'example.com, .example.org')
        ->set('email_domains_denied', 'blocked.example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('email_domains_allowed'))->toBe('example.com, .example.org')
        ->and(Setting::get('email_domains_denied'))->toBe('blocked.example.com');
});
