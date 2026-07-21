<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

final class NewsPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_news', $project);
    }

    public function view(?User $user, News $news): bool
    {
        return $this->authorization->can($user, 'view_news', $news->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'manage_news', $project);
    }

    public function update(User $user, News $news): bool
    {
        return $this->authorization->can($user, 'manage_news', $news->project);
    }

    public function delete(User $user, News $news): bool
    {
        return $this->update($user, $news);
    }

    public function comment(User $user, News $news): bool
    {
        return $this->authorization->can($user, 'comment_news', $news->project);
    }

    public function watch(User $user, News $news): bool
    {
        return $this->authorization->can($user, 'view_news', $news->project);
    }
}
