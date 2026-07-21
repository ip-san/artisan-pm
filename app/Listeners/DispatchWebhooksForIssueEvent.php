<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\WebhookEvent;
use App\Events\IssueCreated;
use App\Events\IssueUpdated;
use App\Http\Resources\Api\V1\IssueResource;
use App\Models\Issue;
use App\Models\Webhook;
use Spatie\WebhookServer\WebhookCall;

final class DispatchWebhooksForIssueEvent
{
    public function handle(IssueCreated|IssueUpdated $event): void
    {
        $webhookEvent = $event instanceof IssueCreated ? WebhookEvent::IssueCreated : WebhookEvent::IssueUpdated;

        $this->dispatchTo($event->issue, $webhookEvent);
    }

    private function dispatchTo(Issue $issue, WebhookEvent $webhookEvent): void
    {
        $webhooks = Webhook::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $issue->project_id))
            ->get()
            ->filter(fn (Webhook $webhook) => $webhook->listensFor($webhookEvent));

        $payload = [
            'event' => $webhookEvent->value,
            'issue' => (new IssueResource($issue))->resolve(),
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
