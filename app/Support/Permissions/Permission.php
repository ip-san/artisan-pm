<?php

namespace App\Support\Permissions;

use App\Enums\PermissionRequirement;
use App\Enums\ProjectModuleKey;

final readonly class Permission
{
    public function __construct(
        public string $key,
        public ?ProjectModuleKey $module = null,
        public PermissionRequirement $requirement = PermissionRequirement::Member,
    ) {}
}
