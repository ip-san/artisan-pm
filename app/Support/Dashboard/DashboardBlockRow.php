<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\Issue;

/**
 * One line of a My page block. Issue rows also carry the issue, so the block can show its status
 * and priority as badges ($statusBadge / $priorityBadge) instead of as words in $meta.
 */
final readonly class DashboardBlockRow
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $meta,
        public ?Issue $issue = null,
        public bool $statusBadge = false,
        public bool $priorityBadge = false,
    ) {}
}
