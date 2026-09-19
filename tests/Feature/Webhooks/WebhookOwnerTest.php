<?php

use App\Enums\EnumerationType;
use App\Enums\UserStatus;
use App\Enums\WebhookEvent;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Webhook;
use App\Services\IssueService;
use App\Services\TimeEntryService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\WebhookServer\CallWebhookJob;

function ownerHookIssue(Project $project, User $author, bool $private = false): App\Models\Issue
{
    return app(IssueService::class)->create([
        'project_id' => $project->id,
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Hooked',
        'is_private' => $private,
    ], $author);
}

/**
 * @param  array<int, string>  $permissions
 */
function ownerHookMember(Project $project, array $permissions, string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility]));

    return $user;
}

test('an ownerless webhook keeps firing for everything', function () {
    Queue::fake();
    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueCreated->value]]);

    ownerHookIssue($project, User::factory()->create());

    Queue::assertPushed(CallWebhookJob::class);
});

test('an owned webhook fires only when the owner holds use_webhooks and may see the issue', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $owner = ownerHookMember($project, ['view_issues', 'use_webhooks']);
    Webhook::factory()->create(['user_id' => $owner->id, 'events' => [WebhookEvent::IssueCreated->value]]);

    ownerHookIssue($project, User::factory()->create());

    Queue::assertPushed(CallWebhookJob::class, 1);
});

test('an owned webhook stays silent without use_webhooks', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $owner = ownerHookMember($project, ['view_issues']);
    Webhook::factory()->create(['user_id' => $owner->id, 'events' => [WebhookEvent::IssueCreated->value]]);

    ownerHookIssue($project, User::factory()->create());

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('an owned webhook never carries an issue its owner may not see', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $owner = ownerHookMember($project, ['view_issues', 'use_webhooks'], 'default');
    Webhook::factory()->create(['user_id' => $owner->id, 'events' => [WebhookEvent::IssueCreated->value]]);

    ownerHookIssue($project, User::factory()->create(), private: true);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('an owned webhook of a locked or removed owner is skipped', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $owner = ownerHookMember($project, ['view_issues', 'use_webhooks']);
    Webhook::factory()->create(['user_id' => $owner->id, 'events' => [WebhookEvent::IssueCreated->value]]);
    $owner->update(['status' => UserStatus::Locked]);

    ownerHookIssue($project, User::factory()->create());

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('an owned webhook does not fire for a project the owner is not in', function () {
    Queue::fake();
    $other = Project::factory()->create();
    $owner = ownerHookMember(Project::factory()->create(), ['view_issues', 'use_webhooks']);
    Webhook::factory()->create(['user_id' => $owner->id, 'events' => [WebhookEvent::IssueCreated->value]]);

    ownerHookIssue($other, User::factory()->create());

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('webhooks_enabled off stops every delivery', function () {
    Queue::fake();
    Setting::set('webhooks_enabled', false);
    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueCreated->value]]);

    ownerHookIssue($project, User::factory()->create());

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('the owner check also applies to time entry webhooks', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $owner = ownerHookMember($project, ['view_time_entries', 'use_webhooks']);
    $blind = ownerHookMember($project, ['use_webhooks']);
    Webhook::factory()->create(['user_id' => $owner->id, 'events' => [WebhookEvent::TimeEntryCreated->value]]);
    Webhook::factory()->create(['user_id' => $blind->id, 'events' => [WebhookEvent::TimeEntryCreated->value]]);

    app(TimeEntryService::class)->create([
        'project_id' => $project->id, 'user_id' => $owner->id, 'hours' => 1, 'spent_on' => '2026-03-10',
        'activity_id' => Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value])->id,
    ]);

    Queue::assertPushed(CallWebhookJob::class, 1);
});

test('the admin form can set and clear the owner, and only an active user qualifies', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $locked = User::factory()->create(['status' => UserStatus::Locked]);

    $form = Livewire::actingAs($admin)->test('webhooks.form')->set('name', 'Owned')->set('url', 'https://example.com/h')->set('events', [WebhookEvent::IssueCreated->value]);
    $form->set('user_id', $locked->id)->call('save')->assertHasErrors(['user_id']);
    $form->set('user_id', $owner->id)->call('save')->assertHasNoErrors();

    $webhook = Webhook::where('name', 'Owned')->firstOrFail();
    expect($webhook->user_id)->toBe($owner->id);

    Livewire::actingAs($admin)->test('webhooks.form', ['webhook' => $webhook])->assertSet('user_id', $owner->id)->set('user_id', null)->call('save')->assertHasNoErrors();
    expect($webhook->fresh()->user_id)->toBeNull();
});

test('the settings page saves the webhooks_enabled switch', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->assertSet('webhooks_enabled', true)->set('webhooks_enabled', false)->call('save')->assertHasNoErrors();

    expect(Setting::get('webhooks_enabled'))->toBeFalse();
});
