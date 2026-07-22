<?php

use App\Enums\WebhookEvent;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WikiPage;
use App\Services\IssueService;
use App\Services\WikiPageService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
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

test('a comment-only update (no attribute changes) still dispatches issue.updated', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueUpdated->value]]);
    $actor = User::factory()->create();
    $issue = Issue::factory()->for($project)->create(webhookIssueDefaults());

    app(IssueService::class)->update($issue, [], $actor, 'Just a comment, nothing else changed');

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->payload['event'] === WebhookEvent::IssueUpdated->value);
});

test('deleting an issue dispatches a webhook subscribed to issue.deleted', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $webhook = Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::IssueDeleted->value]]);
    $issue = Issue::factory()->for($project)->create(webhookIssueDefaults());
    $issueId = $issue->id;

    app(IssueService::class)->delete($issue);

    expect(Issue::find($issueId))->toBeNull();

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->webhookUrl === $webhook->url
        && $job->payload['issue']['id'] === $issueId
        && $job->payload['event'] === WebhookEvent::IssueDeleted->value);
});

test('a webhook subscribed only to issue.created is not dispatched on delete', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueCreated->value]]);
    $issue = Issue::factory()->for($project)->create(webhookIssueDefaults());

    app(IssueService::class)->delete($issue);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('deleting an issue from the issue detail page dispatches issue.deleted', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::IssueDeleted->value]]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'delete_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $issue = Issue::factory()->for($project)->create(webhookIssueDefaults());

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteIssue');

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->payload['event'] === WebhookEvent::IssueDeleted->value);
});

test('creating a wiki page dispatches a webhook subscribed to wiki_page.created', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $webhook = Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::WikiPageCreated->value]]);
    $author = User::factory()->create();

    $page = app(WikiPageService::class)->create($project, ['title' => 'Introduction'], 'Some text', $author);

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->webhookUrl === $webhook->url
        && $job->payload['wiki_page']['id'] === $page->id
        && $job->payload['event'] === WebhookEvent::WikiPageCreated->value);
});

test('updating a wiki page dispatches a webhook subscribed to wiki_page.updated', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::WikiPageUpdated->value]]);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Introduction'], 'Original text', $author);

    app(WikiPageService::class)->update($page, [], 'Updated text', $author);

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->payload['event'] === WebhookEvent::WikiPageUpdated->value);
});

test('deleting a wiki page dispatches a webhook subscribed to wiki_page.deleted', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $webhook = Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::WikiPageDeleted->value]]);
    $page = WikiPage::factory()->for($project)->create();
    $pageId = $page->id;

    app(WikiPageService::class)->delete($page);

    expect(WikiPage::find($pageId))->toBeNull();

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->webhookUrl === $webhook->url
        && $job->payload['wiki_page']['id'] === $pageId
        && $job->payload['event'] === WebhookEvent::WikiPageDeleted->value);
});

test('a webhook subscribed only to wiki_page.created is not dispatched on wiki page delete', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::WikiPageCreated->value]]);
    $page = WikiPage::factory()->for($project)->create();

    app(WikiPageService::class)->delete($page);

    Queue::assertNotPushed(CallWebhookJob::class);
});

test('deleting a wiki page from the wiki show page dispatches wiki_page.deleted', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['events' => [WebhookEvent::WikiPageDeleted->value]]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_wiki_pages', 'edit_wiki_pages', 'delete_wiki_pages']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('delete');

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => $job->payload['event'] === WebhookEvent::WikiPageDeleted->value);
});

test('a webhook with a secret signs its request', function () {
    Queue::fake();

    $project = Project::factory()->create();
    Webhook::factory()->create(['secret' => 'top-secret', 'events' => [WebhookEvent::IssueCreated->value]]);
    $author = User::factory()->create();

    app(IssueService::class)->create([...webhookIssueDefaults(), 'project_id' => $project->id, 'subject' => 'Signed issue'], $author);

    Queue::assertPushed(CallWebhookJob::class, fn (CallWebhookJob $job) => array_key_exists('Signature', $job->headers));
});
