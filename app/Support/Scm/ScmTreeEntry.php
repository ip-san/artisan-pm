<?php

declare(strict_types=1);

namespace App\Support\Scm;

final readonly class ScmTreeEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDirectory,
    ) {}
}
