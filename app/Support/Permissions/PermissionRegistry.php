<?php

declare(strict_types=1);

namespace App\Support\Permissions;

use App\Enums\PermissionRequirement;
use App\Enums\ProjectModuleKey;

/**
 * Boot-time catalog of every permission key the application understands,
 * analogous to Redmine's `Redmine::AccessControl.map`. Core permissions are
 * registered by PermissionServiceProvider; plugins will register their own
 * permissions into the same instance once the plugin system exists.
 */
final class PermissionRegistry
{
    /** @var array<string, Permission> */
    private array $permissions = [];

    public function register(string $key, ?ProjectModuleKey $module = null, PermissionRequirement $requirement = PermissionRequirement::Member): void
    {
        $this->permissions[$key] = new Permission($key, $module, $requirement);
    }

    public function get(string $key): ?Permission
    {
        return $this->permissions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->permissions[$key]);
    }

    /**
     * @return array<string, Permission>
     */
    public function all(): array
    {
        return $this->permissions;
    }

    /**
     * Permissions that may legally be granted to a role with the given
     * builtin-ness. Used to build the admin role-edit UI.
     *
     * @return array<string, Permission>
     */
    public function assignableTo(bool $isBuiltinAnonymous, bool $isBuiltinNonMember = false): array
    {
        return array_filter($this->permissions, function (Permission $permission) use ($isBuiltinAnonymous, $isBuiltinNonMember) {
            if ($isBuiltinAnonymous) {
                return $permission->requirement === PermissionRequirement::None;
            }

            if ($isBuiltinNonMember) {
                return $permission->requirement !== PermissionRequirement::Member;
            }

            return true;
        });
    }
}
