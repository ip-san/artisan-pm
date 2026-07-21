<?php

declare(strict_types=1);

namespace App\Support\Search;

use Carbon\CarbonInterface;

final readonly class SearchResult
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public ?string $excerpt,
        public CarbonInterface $updatedAt,
    ) {}
}
