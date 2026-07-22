<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookEvent;
use App\Events\WikiPageCreated;
use App\Events\WikiPageDeleted;
use App\Events\WikiPageUpdated;
use App\Http\Resources\Api\V1\WikiPageResource;
use App\Models\Webhook;
use App\Models\WikiPage;
use Spatie\WebhookServer\WebhookCall;

final class DispatchWebhooksForWikiPageEvent
{
    public function handle(WikiPageCreated|WikiPageUpdated|WikiPageDeleted $event): void
    {
        $webhookEvent = match ($event::class) {
            WikiPageCreated::class => WebhookEvent::WikiPageCreated,
            WikiPageUpdated::class => WebhookEvent::WikiPageUpdated,
            WikiPageDeleted::class => WebhookEvent::WikiPageDeleted,
        };

        $this->dispatchTo($event->wikiPage, $webhookEvent);
    }

    private function dispatchTo(WikiPage $wikiPage, WebhookEvent $webhookEvent): void
    {
        $webhooks = Webhook::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $wikiPage->project_id))
            ->get()
            ->filter(fn (Webhook $webhook) => $webhook->listensFor($webhookEvent));

        $payload = [
            'event' => $webhookEvent->value,
            'wiki_page' => (new WikiPageResource($wikiPage))->resolve(),
        ];

        foreach ($webhooks as $webhook) {
            $call = WebhookCall::create()
                ->url($webhook->url)
                ->payload($payload);

            if ($webhook->secret !== null) {
                $call->useSecret($webhook->secret);
            } else {
                $call->doNotSign();
            }

            $call->dispatch();
        }
    }
}
