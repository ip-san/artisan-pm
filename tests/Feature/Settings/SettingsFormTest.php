<?php

use App\Enums\ProjectModuleKey;
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

test('an admin can configure default project modules and trackers', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('default_projects_modules', [ProjectModuleKey::IssueTracking->value])
        ->set('default_projects_tracker_ids', [$tracker->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('default_projects_modules'))->toBe([ProjectModuleKey::IssueTracking->value])
        ->and(Setting::get('default_projects_tracker_ids'))->toBe([$tracker->id]);
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
