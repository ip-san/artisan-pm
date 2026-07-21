<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NewsComment;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class NewsCommentPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Redmine ties comment deletion to manage_news, not to comment
     * authorship — there's no "delete own comment" permission on News.
     */
    public function delete(User $user, NewsComment $comment): bool
    {
        return $this->authorization->can($user, 'manage_news', $comment->news->project);
    }
}
