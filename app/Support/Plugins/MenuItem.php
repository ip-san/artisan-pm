<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use Closure;

final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $url,
        public ?Closure $visibleWhen = null,
    ) {}

    public function isVisible(): bool
    {
        return $this->visibleWhen === null || (bool) ($this->visibleWhen)();
    }
}
