<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\News;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NewsCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly News $news,
    ) {}
}
