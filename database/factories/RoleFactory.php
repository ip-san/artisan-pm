<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'builtin' => null,
            'permissions' => [],
            'position' => 1,
        ];
    }

    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => $permissions,
        ]);
    }

    /**
     * Limits one of the role's issue permissions to the given trackers
     * (Redmine's Role#set_permission_trackers).
     *
     * @param  array<int>  $trackerIds
     */
    public function limitedToTrackers(string $permission, array $trackerIds): static
    {
        return $this->afterMaking(fn (Role $role) => $role->setPermissionTrackers($permission, $trackerIds));
    }
}
