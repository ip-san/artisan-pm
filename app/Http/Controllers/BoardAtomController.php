<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Support\Activity\ActivityEntry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Matches Redmine's BoardsController#show responding to format.atom:
 * every message in this board (topics and replies alike), newest
 * first. Capped the same way the project activity feed is (shared
 * ActivityFeedController::LIMIT) rather than exposing
 * Setting.feeds_limit as a configurable value.
 */
final class BoardAtomController extends Controller
{
    public function __invoke(Project $project, Board $board): Response
    {
        Gate::authorize('view', $board);

        $entries = $board->messages()
            ->with(['author', 'parent'])
            ->latest('id')
            ->limit(ActivityFeedController::LIMIT)
            ->get()
            ->map(fn (Message $message) => new ActivityEntry(
                type: 'message',
                title: $message->isTopic() ? $message->subject : "{$board->name}: {$message->parent->subject}",
                url: route('messages.show', [$project, $board, $message->isTopic() ? $message : $message->parent]),
                authorName: $message->author->name,
                occurredAt: $message->created_at ?? throw new LogicException('Message is missing created_at.'),
            ));

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => "{$project->name}: {$board->name} - ".config('app.name'),
            'alternateUrl' => route('boards.show', [$project, $board]),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
