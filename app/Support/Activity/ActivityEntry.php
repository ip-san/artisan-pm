<?php

declare(strict_types=1);

namespace App\Support\Activity;

use Carbon\CarbonInterface;

final readonly class ActivityEntry
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public ?string $authorName,
        public CarbonInterface $occurredAt,
    ) {}
}
