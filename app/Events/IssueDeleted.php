<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Issue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class IssueDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Issue $issue,
    ) {}
}
