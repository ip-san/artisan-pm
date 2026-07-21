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

#[Fillable(['name', 'builtin', 'permissions', 'position', 'issues_visibility', 'time_entries_visibility', 'assignable'])]
final class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare these defaults here too —
     * otherwise a just-created Role's in-memory value is null/false even
     * though the roles table defaults to 'all'/'all'/true.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'issues_visibility' => 'all',
        'time_entries_visibility' => 'all',
        'assignable' => true,
    ];

    protected function casts(): array
    {
        return [
            'builtin' => RoleBuiltin::class,
            'permissions' => 'array',
            'issues_visibility' => IssueVisibility::class,
            'time_entries_visibility' => TimeEntryVisibility::class,
            'assignable' => 'boolean',
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
