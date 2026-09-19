<?php

use App\Enums\WebhookEvent;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\WebhookServer\CallWebhookJob;

function newsWebhookManager(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_news', 'manage_news']])
    );

    return $user;
}

function newsHookPayloads(): array
{
    return Queue::pushed(CallWebhookJob::class)->map(fn (CallWebhookJob $job) => $job->payload)->all();
}

test('the news events are offered on the webhook form', function () {
    expect(collect(WebhookEvent::cases())->pluck('value')->all())->toContain('news.created', 'news.updated', 'news.deleted');
});

test('creating news in the web form dispatches news.created with the news payload', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $user = newsWebhookManager($project);
    Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::NewsCreated->value]]);

    Livewire::actingAs($user)->test('news.form', ['project' => $project])
        ->set('title', 'Big announcement')
        ->set('summary', 'Short')
        ->set('description', 'Long text')
        ->call('save');

    $payloads = newsHookPayloads();

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['event'])->toBe('news.created')
        ->and($payloads[0]['news']['title'])->toBe('Big announcement')
        ->and($payloads[0]['news']['project_id'])->toBe($project->id);
});

test('editing and deleting news dispatch news.updated and news.deleted', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $user = newsWebhookManager($project);
    $news = News::factory()->for($project)->create(['title' => 'Before']);
    Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::NewsUpdated->value, WebhookEvent::NewsDeleted->value]]);

    Livewire::actingAs($user)->test('news.form', ['project' => $project, 'news' => $news])
        ->set('title', 'After')
        ->call('save');

    Livewire::actingAs($user)->test('news.show', ['project' => $project, 'news' => $news->fresh()])
        ->call('delete');

    $payloads = newsHookPayloads();

    expect(collect($payloads)->pluck('event')->all())->toBe(['news.updated', 'news.deleted'])
        ->and($payloads[0]['news']['title'])->toBe('After')
        ->and(News::query()->whereKey($news->id)->exists())->toBeFalse();
});

test('the API news endpoints dispatch the same events', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $user = newsWebhookManager($project);
    Webhook::factory()->create(['url' => 'https://example.com/hook', 'events' => [WebhookEvent::NewsCreated->value, WebhookEvent::NewsUpdated->value, WebhookEvent::NewsDeleted->value]]);

    Passport::actingAs($user);

    $id = $this->postJson("/api/v1/projects/{$project->id}/news", ['title' => 'Via API', 'description' => 'Body'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/news/{$id}", ['title' => 'Via API v2'])->assertOk();
    $this->deleteJson("/api/v1/news/{$id}")->assertNoContent();

    expect(collect(newsHookPayloads())->pluck('event')->all())->toBe(['news.created', 'news.updated', 'news.deleted']);
});

test('a webhook that is not subscribed, inactive, or scoped to another project gets no news calls', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $user = newsWebhookManager($project);
    Webhook::factory()->create(['url' => 'https://example.com/a', 'events' => [WebhookEvent::IssueCreated->value]]);
    Webhook::factory()->create(['url' => 'https://example.com/b', 'events' => [WebhookEvent::NewsCreated->value], 'is_active' => false]);
    Webhook::factory()->create(['url' => 'https://example.com/c', 'events' => [WebhookEvent::NewsCreated->value], 'project_id' => $other->id]);
    Webhook::factory()->create(['url' => 'https://example.com/d', 'events' => [WebhookEvent::NewsCreated->value], 'project_id' => $project->id]);

    Passport::actingAs($user);
    $this->postJson("/api/v1/projects/{$project->id}/news", ['title' => 'Scoped', 'description' => 'Body'])->assertCreated();

    $urls = Queue::pushed(CallWebhookJob::class)->map(fn (CallWebhookJob $job) => $job->webhookUrl)->all();

    expect($urls)->toBe(['https://example.com/d']);
});

test('an API-created news item makes its author a watcher, like the web form', function () {
    $project = Project::factory()->create();
    $user = newsWebhookManager($project);

    Passport::actingAs($user);

    $id = $this->postJson("/api/v1/projects/{$project->id}/news", ['title' => 'Watched', 'description' => 'Body'])->assertCreated()->json('data.id');

    expect(News::findOrFail($id)->watchers()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('an API-created news item is mailed like a web-created one', function () {
    Illuminate\Support\Facades\Event::fake([App\Events\NewsCreated::class]);
    $project = Project::factory()->create();
    $user = newsWebhookManager($project);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/news", ['title' => 'Mailed', 'description' => 'Body'])->assertCreated();

    Illuminate\Support\Facades\Event::assertDispatched(App\Events\NewsCreated::class);
});
