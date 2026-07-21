<?php

use App\Enums\WebhookEvent;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Webhook;
use App\Services\IssueService;
use Illuminate\Support\Facades\Queue;
use Spatie\WebhookServer\CallWebhookJob;

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int}
 */
function webhookIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
}

test('creating an issue dispatches a webhook subscribed to issue.created', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $webhook = Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::IssueCreated->value]]);
    $author = User::factory()->create();

    $issue = app(IssueService::class)->create([...webhookIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->webhookUrl === $webhook->url
        && $job->payload['issue']['id'] === $issue->id
        && $job->payload['event'] === WebhookEvent::IssueCreated->value);
});

test('a webhook not subscribed to the event is not dispatched', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueUpdated->value]]);
    $author = User::factory()->create();

    app(IssueService::class)->create([...webhookIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('a webhook scoped to a different project is not dispatched', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    Webhook::factory()->create(['project_id' => $otherProject->id, 'events' => [WebhookEvent::IssueCreated->value]]);
    $author = User::factory()->create();

    app(IssueService::class)->create([...webhookIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('an inactive webhook is not dispatched', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['is_active' => false, 'events' => [WebhookEvent::IssueCreated->value]]);
    $author = User::factory()->create();

    app(IssueService::class)->create([...webhookIssueDefaults(), 'project_id' => $project->id, 'subject' => 'New issue'], $author);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('updating an issue dispatches a webhook subscribed to issue.updated, but not a no-op update', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueUpdated->value]]);
    $actor = User::factory()->create();
    $issue = Issue::factory()->for($project)->create(webhookIssueDefaults());

    app(IssueService::class)->update($issue, [], $actor);
    Queue::assertNotPushed(CallWebhookJob::class);

    app(IssueService::class)->update($issue, ['subject' => 'Changed'], $actor);
    Queue::assertPushed(CallWebhookJob::class);
});

test('a webhook with a secret signs its request', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['secret' => 'top-secret', 'events' => [WebhookEvent::IssueCreated->value]]);
    $author = User::factory()->create();

    app(IssueService::class)->create([...webhookIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Signed issue'], $author);

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => array_key_exists('Signature', $job->headers));
});
