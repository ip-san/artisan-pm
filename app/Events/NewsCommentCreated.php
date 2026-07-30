<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\NewsComment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NewsCommentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NewsComment $comment,
    ) {}
}
