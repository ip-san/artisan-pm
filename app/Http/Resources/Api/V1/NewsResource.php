<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat project_id/author_id, matching this app's own resource convention
 * (WikiPageResource/IssueCategoryResource) rather than Redmine's nested
 * {id, name} project/author objects. comments_count is computed via
 * withCount('comments') at the query site — there's no such column.
 *
 * @property News $resource
 */
final class NewsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $news = $this->resource;
        $isShow = $request->route()?->getName() === 'api.news.show';

        return [
            'id' => $news->id,
            'project_id' => $news->project_id,
            'author_id' => $news->author_id,
            'title' => $news->title,
            'summary' => $news->summary,
            'description' => $news->description,
            'comments_count' => $news->comments_count,
            // Redmine's news/show.api.rsb (not the list): ?include=attachments,comments.
            ...($isShow && AttachmentResource::included($request, 'attachments') ? ['attachments' => AttachmentResource::listFor($news->attachments(), $request)] : []),
            ...($isShow && AttachmentResource::included($request, 'comments') ? ['comments' => $news->comments()->with('author')->oldest()->get()->map(fn ($comment) => [
                'id' => $comment->id,
                'author' => $comment->author !== null ? ['id' => $comment->author->id, 'name' => $comment->author->displayName()] : null,
                'content' => $comment->content,
                'created_at' => $comment->created_at->toIso8601String(),
            ])->all()] : []),
            'created_at' => $news->created_at->toIso8601String(),
            'updated_at' => $news->updated_at->toIso8601String(),
        ];
    }
}
