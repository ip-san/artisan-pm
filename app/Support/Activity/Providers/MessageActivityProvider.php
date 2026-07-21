<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

final class MessageActivityProvider implements ActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'message';
    }

    public function label(): string
    {
        return 'フォーラム';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_messages', $project)) {
            return collect();
        }

        return Message::query()
            ->whereHas('board', fn ($query) => $query->where('project_id', $project->id))
            ->whereBetween('created_at', [$from, $to])
            ->with(['board', 'author', 'parent'])
            ->get()
            ->map(fn (Message $message) => new ActivityEntry(
                type: $this->type(),
                title: $message->isTopic() ? $message->subject : "{$message->board->name}: {$message->parent->subject}",
                url: route('messages.show', [$project, $message->board, $message->isTopic() ? $message : $message->parent]),
                authorName: $message->author->name,
                occurredAt: $message->created_at ?? throw new LogicException('Message is missing created_at.'),
            ));
    }
}
