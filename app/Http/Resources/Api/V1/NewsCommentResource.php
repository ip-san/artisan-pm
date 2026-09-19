<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\NewsComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat news_id/author_id like the other resources here.
 *
 * @property NewsComment $resource
 */
final class NewsCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'news_id' => $comment->news_id,
            'author_id' => $comment->author_id,
            'content' => $comment->content,
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}
