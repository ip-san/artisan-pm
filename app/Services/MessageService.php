<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\MessagePosted;
use App\Models\Board;
use App\Models\Message;
use App\Models\User;

/**
 * Writes forum replies and topic moves together with what follows them.
 * Authorization stays with the caller.
 */
final class MessageService
{
    /**
     * Posts a reply to $topic in $board (subject "RE: <topic>", as
     * Redmine's MessagesController#reply), mails it through MessagePosted
     * and bumps the topic's last activity.
     */
    public function reply(Board $board, Message $topic, User $author, string $content): Message
    {
        $reply = Message::create([
            'board_id' => $board->id,
            'parent_id' => $topic->id,
            'author_id' => $author->id,
            'subject' => "RE: {$topic->subject}",
            'content' => $content,
        ]);

        MessagePosted::dispatch($reply);
        $topic->touch();

        return $reply;
    }

    /**
     * Moves a topic to $board. Replies carry their own board_id rather than
     * inheriting it from the topic, so both need updating to keep them
     * together.
     */
    public function moveTopic(Message $topic, Board $board): void
    {
        Message::query()
            ->where(fn ($q) => $q->whereKey($topic->id)->orWhere('parent_id', $topic->id))
            ->update(['board_id' => $board->id]);
    }
}
