<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookEvent;
use App\Events\NewsCreated;
use App\Events\NewsDeleted;
use App\Events\NewsUpdated;
use App\Http\Resources\Api\V1\NewsResource;
use App\Models\News;
use App\Models\Webhook;

final class DispatchWebhooksForNewsEvent
{
    public function handle(NewsCreated|NewsUpdated|NewsDeleted $event): void
    {
        $webhookEvent = match ($event::class) {
            NewsCreated::class => WebhookEvent::NewsCreated,
            NewsUpdated::class => WebhookEvent::NewsUpdated,
            NewsDeleted::class => WebhookEvent::NewsDeleted,
        };

        $this->dispatchTo($event->news, $webhookEvent);
    }

    private function dispatchTo(News $news, WebhookEvent $webhookEvent): void
    {
        $webhooks = Webhook::deliverableFor($news, $news->project_id, $webhookEvent);

        $payload = [
            'event' => $webhookEvent->value,
            'news' => (new NewsResource($news->loadCount('comments')))->resolve(),
        ];

        foreach ($webhooks as $webhook) {
            $webhook->deliver($payload);
        }
    }
}
