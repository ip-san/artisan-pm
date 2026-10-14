<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NewsCommentCreated;
use App\Events\NewsCreated;
use App\Events\NewsDeleted;
use App\Events\NewsUpdated;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\User;

/**
 * Writes news items and their comments together with what follows them:
 * the author watching their own announcement and the events that drive
 * mail and webhooks. Authorization stays with the caller.
 */
final class NewsService
{
    /**
     * @param  array{title: string, summary?: ?string, description: string}  $attributes
     */
    public function create(Project $project, User $author, array $attributes): News
    {
        $news = News::create([...$attributes, 'project_id' => $project->id, 'author_id' => $author->id]);
        $news->watchers()->create(['user_id' => $author->id]);

        NewsCreated::dispatch($news);

        return $news;
    }

    /**
     * Redmine has no notification for a News *edit* — only after_create
     * (news_added) and a comment being posted are ever mailed — so
     * NewsUpdated drives webhooks only.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(News $news, array $attributes): News
    {
        $news->update($attributes);

        NewsUpdated::dispatch($news);

        return $news;
    }

    /**
     * Dispatched before the row is removed, so listeners still see the
     * whole news item.
     */
    public function delete(News $news): void
    {
        NewsDeleted::dispatch($news);

        $news->delete();
    }

    public function comment(News $news, User $author, string $content): NewsComment
    {
        $comment = NewsComment::create([
            'news_id' => $news->id,
            'author_id' => $author->id,
            'content' => $content,
        ]);

        NewsCommentCreated::dispatch($comment);

        return $comment;
    }
}
