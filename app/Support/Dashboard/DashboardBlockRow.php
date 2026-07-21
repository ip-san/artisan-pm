<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

final readonly class DashboardBlockRow
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $meta,
    ) {}
}
