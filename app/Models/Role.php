<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'builtin', 'permissions', 'position', 'issues_visibility', 'time_entries_visibility', 'assignable', 'all_roles_managed'])]
final class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare these defaults here too —
     * otherwise a just-created Role's in-memory value is null/false even
     * though the roles table defaults to 'all'/'all'/true/true.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'issues_visibility' => 'all',
        'time_entries_visibility' => 'all',
        'assignable' => true,
        'all_roles_managed' => true,
    ];

    protected function casts(): array
    {
        return [
            'builtin' => RoleBuiltin::class,
            'permissions' => 'array',
            'issues_visibility' => IssueVisibility::class,
            'time_entries_visibility' => TimeEntryVisibility::class,
            'assignable' => 'boolean',
            'all_roles_managed' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'member_roles')->withTimestamps();
    }

    /**
     * The roles a member holding this role is allowed to assign to other
     * members (add/remove on the project members screen) — only consulted
     * when all_roles_managed is false. Matches Redmine's Role#managed_roles
     * has_and_belongs_to_many.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function managedRoles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'role_managed_role', 'role_id', 'managed_role_id');
    }

    /**
     * @return array<string>
     */
    public function permissionKeys(): array
    {
        return $this->permissions ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionKeys(), true);
    }
}
