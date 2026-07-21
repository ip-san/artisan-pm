<?php

declare(strict_types=1);

namespace App\Support\Scm;

final readonly class ScmFileChange
{
    /**
     * @param  string  $action  single-letter action: A(dded), M(odified), D(eleted), R(enamed), etc. — as reported by the adapter's underlying VCS
     */
    public function __construct(
        public string $path,
        public string $action,
    ) {}
}
