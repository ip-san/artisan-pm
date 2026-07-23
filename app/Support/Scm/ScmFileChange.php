<?php

declare(strict_types=1);

namespace App\Support\Scm;

final readonly class ScmFileChange
{
    /**
     * @param  string  $action  single-letter action: A(dded), M(odified), D(eleted), R(enamed), etc. — as reported by the adapter's underlying VCS
     * @param  ?string  $fromPath  the file's path before this change, when the adapter detected a rename/copy — null otherwise
     */
    public function __construct(
        public string $path,
        public string $action,
        public ?string $fromPath = null,
    ) {}
}
