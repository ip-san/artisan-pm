<?php

declare(strict_types=1);

namespace App\Support\Scm;

use DateTimeImmutable;

final readonly class ScmLogEntry
{
    /**
     * @param  array<int, ScmFileChange>  $files
     */
    public function __construct(
        public string $revision,
        public string $committer,
        public DateTimeImmutable $committedOn,
        public string $message,
        public array $files,
    ) {}
}
