<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasReactions;
use Database\Factories\NewsCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['news_id', 'author_id', 'content'])]
final class NewsComment extends Model
{
    /** @use HasFactory<NewsCommentFactory> */
    use HasFactory, HasReactions;

    /**
     * @return BelongsTo<News, $this>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
