<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookEvent;
use App\Events\VersionCreated;
use App\Events\VersionDeleted;
use App\Events\VersionUpdated;
use App\Http\Resources\Api\V1\VersionResource;
use App\Models\Version;
use App\Models\Webhook;
use Spatie\WebhookServer\WebhookCall;

final class DispatchWebhooksForVersionEvent
{
    public function handle(VersionCreated|VersionUpdated|VersionDeleted $event): void
    {
        $webhookEvent = match ($event::class) {
            VersionCreated::class => WebhookEvent::VersionCreated,
            VersionUpdated::class => WebhookEvent::VersionUpdated,
            VersionDeleted::class => WebhookEvent::VersionDeleted,
        };

        $this->dispatchTo($event->version, $webhookEvent);
    }

    private function dispatchTo(Version $version, WebhookEvent $webhookEvent): void
    {
        $webhooks = Webhook::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $version->project_id))
            ->get()
            ->filter(fn (Webhook $webhook) => $webhook->listensFor($webhookEvent));

        $payload = [
            'event' => $webhookEvent->value,
            'version' => (new VersionResource($version))->resolve(),
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
