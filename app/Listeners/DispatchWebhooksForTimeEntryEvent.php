<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookEvent;
use App\Events\TimeEntryCreated;
use App\Events\TimeEntryDeleted;
use App\Events\TimeEntryUpdated;
use App\Http\Resources\Api\V1\TimeEntryResource;
use App\Models\TimeEntry;
use App\Models\Webhook;
use Spatie\WebhookServer\WebhookCall;

final class DispatchWebhooksForTimeEntryEvent
{
    public function handle(TimeEntryCreated|TimeEntryUpdated|TimeEntryDeleted $event): void
    {
        $webhookEvent = match ($event::class) {
            TimeEntryCreated::class => WebhookEvent::TimeEntryCreated,
            TimeEntryUpdated::class => WebhookEvent::TimeEntryUpdated,
            TimeEntryDeleted::class => WebhookEvent::TimeEntryDeleted,
        };

        $this->dispatchTo($event->timeEntry, $webhookEvent);
    }

    private function dispatchTo(TimeEntry $timeEntry, WebhookEvent $webhookEvent): void
    {
        $webhooks = Webhook::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $timeEntry->project_id))
            ->get()
            ->filter(fn (Webhook $webhook) => $webhook->listensFor($webhookEvent));

        $payload = [
            'event' => $webhookEvent->value,
            'time_entry' => (new TimeEntryResource($timeEntry))->resolve(),
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
