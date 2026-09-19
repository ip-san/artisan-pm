<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowTransition;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultConfigurationSeeder;
use Livewire\Livewire;

test('an empty installation counts as unconfigured and the seeder fills it in Japanese', function () {
    expect(DefaultConfigurationSeeder::isConfigured())->toBeFalse();

    (new DefaultConfigurationSeeder('ja'))->run();

    expect(Tracker::query()->pluck('name')->all())->toEqualCanonicalizing(['バグ', '機能', 'サポート'])
        ->and(IssueStatus::query()->pluck('name')->all())->toEqualCanonicalizing(['新規', '進行中', '解決', 'フィードバック', '終了', '却下'])
        ->and(IssueStatus::query()->where('is_closed', true)->pluck('name')->all())->toEqualCanonicalizing(['終了', '却下'])
        ->and(Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('is_default', true)->value('name'))->toBe('通常')
        ->and(Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->where('is_default', true)->value('name'))->toBe('開発作業')
        ->and(Role::query()->whereNotNull('builtin')->count())->toBe(2)
        ->and(Role::query()->whereNull('builtin')->pluck('name')->all())->toEqualCanonicalizing(['マネージャー', '開発者', '報告者'])
        ->and(DefaultConfigurationSeeder::isConfigured())->toBeTrue();
});

test('the English set and the workflow are wired through the same keys', function () {
    (new DefaultConfigurationSeeder('en'))->run();

    $manager = Role::query()->where('name', 'Manager')->firstOrFail();
    $bug = Tracker::query()->where('name', 'Bug')->firstOrFail();
    $new = IssueStatus::query()->where('name', 'New')->firstOrFail();
    $rejected = IssueStatus::query()->where('name', 'Rejected')->firstOrFail();

    expect(WorkflowTransition::query()->where(['tracker_id' => $bug->id, 'role_id' => $manager->id, 'old_status_id' => $new->id, 'new_status_id' => $rejected->id])->exists())->toBeTrue()
        // 3 trackers x (3 roles x 5 common + 3 manager-only) transitions.
        ->and(WorkflowTransition::query()->count())->toBe(3 * (3 * 5 + 3));
});

test('an unknown locale falls back to English names', function () {
    (new DefaultConfigurationSeeder('xx'))->run();

    expect(Tracker::query()->where('name', 'Bug')->exists())->toBeTrue();
});

test('running the seeder twice creates nothing new', function () {
    (new DefaultConfigurationSeeder('ja'))->run();
    $counts = [Tracker::count(), IssueStatus::count(), Role::count(), Enumeration::count(), WorkflowTransition::count()];

    (new DefaultConfigurationSeeder('ja'))->run();

    expect([Tracker::count(), IssueStatus::count(), Role::count(), Enumeration::count(), WorkflowTransition::count()])->toBe($counts);
});

test('the database seeder still builds the demo project on top of the defaults', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Project::query()->where('identifier', 'demo-project')->exists())->toBeTrue()
        ->and(Tracker::query()->where('name', 'Bug')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'admin@example.com')->exists())->toBeTrue();
});

test('the admin page loads the defaults once and then refuses', function () {
    $admin = User::factory()->admin()->create();

    $page = Livewire::actingAs($admin)->test('admin.default-configuration')->set('locale', 'ja')->call('load')->assertHasNoErrors();

    expect(Tracker::query()->where('name', 'バグ')->exists())->toBeTrue();
    $page->assertSee('すでに設定があるため');

    Livewire::actingAs($admin)->test('admin.default-configuration')->set('locale', 'en')->call('load')->assertHasErrors(['locale']);
    expect(Tracker::query()->where('name', 'Bug')->exists())->toBeFalse();
});

test('existing configuration blocks the load and the page says so', function () {
    Tracker::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())->test('admin.default-configuration')->assertSee('すでに設定があるため');
});

test('the locale must be one we ship and only administrators may use the page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('admin.default-configuration')->set('locale', 'fr')->call('load')->assertHasErrors(['locale']);
    Livewire::actingAs(User::factory()->create())->test('admin.default-configuration')->assertForbidden();
});
