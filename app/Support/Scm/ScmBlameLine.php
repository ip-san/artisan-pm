<?php

declare(strict_types=1);

namespace App\Support\Scm;

final readonly class ScmBlameLine
{
    public function __construct(
        public string $revision,
        public string $author,
        public string $content,
    ) {}
}
