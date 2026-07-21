<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class MessagePolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_messages', $project);
    }

    public function view(?User $user, Message $message): bool
    {
        return $this->authorization->can($user, 'view_messages', $message->board->project);
    }

    public function create(User $user, Board $board): bool
    {
        return $this->authorization->can($user, 'add_messages', $board->project);
    }

    /**
     * Locking a topic blocks new replies outright, even for members who
     * could otherwise post — matching Redmine's cannot_reply_to_locked_topic
     * validation, which isn't conditioned on any permission.
     */
    public function reply(User $user, Message $topic): bool
    {
        return ! $topic->is_locked && $this->authorization->can($user, 'add_messages', $topic->board->project);
    }

    public function update(User $user, Message $message): bool
    {
        $project = $message->board->project;

        if ($message->author_id === $user->id && $this->authorization->can($user, 'edit_own_messages', $project)) {
            return true;
        }

        return $this->authorization->can($user, 'edit_messages', $project);
    }

    public function delete(User $user, Message $message): bool
    {
        $project = $message->board->project;

        if ($message->author_id === $user->id && $this->authorization->can($user, 'delete_own_messages', $project)) {
            return true;
        }

        return $this->authorization->can($user, 'delete_messages', $project);
    }

    /**
     * Sticky/locked toggles are restricted to full edit_messages holders
     * even for a member editing their own topic, matching Redmine's
     * safe_attributes condition on 'locked'/'sticky'.
     */
    public function manageFlags(User $user, Message $message): bool
    {
        return $this->authorization->can($user, 'edit_messages', $message->board->project);
    }
}
