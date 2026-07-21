<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'builtin', 'permissions', 'position', 'issues_visibility'])]
final class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare issues_visibility's default
     * here too — otherwise a just-created Role's in-memory value is null
     * even though the roles table default is 'all'.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'issues_visibility' => 'all',
    ];

    protected function casts(): array
    {
        return [
            'builtin' => RoleBuiltin::class,
            'permissions' => 'array',
            'issues_visibility' => IssueVisibility::class,
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
