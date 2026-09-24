<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use App\Enums\TimeEntryVisibility;
use App\Enums\UsersVisibility;
use App\Support\Permissions\PermissionRegistry;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

#[Fillable(['name', 'builtin', 'permissions', 'settings', 'position', 'issues_visibility', 'time_entries_visibility', 'users_visibility', 'assignable', 'all_roles_managed', 'default_time_entry_activity_id'])]
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
        'users_visibility' => 'all',
        'assignable' => true,
        'all_roles_managed' => true,
    ];

    protected function casts(): array
    {
        return [
            'builtin' => RoleBuiltin::class,
            'permissions' => 'array',
            'settings' => 'array',
            'issues_visibility' => IssueVisibility::class,
            'time_entries_visibility' => TimeEntryVisibility::class,
            'users_visibility' => UsersVisibility::class,
            'assignable' => 'boolean',
            'all_roles_managed' => 'boolean',
        ];
    }

    /**
     * The shared time entry activity members holding this role start with
     * when logging time (Redmine's Role#default_time_entry_activity).
     *
     * @return BelongsTo<Enumeration, $this>
     */
    public function defaultTimeEntryActivity(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'default_time_entry_activity_id');
    }

    /**
     * Roles that can be given to a project member — everything except
     * the builtin Anonymous/NonMember placeholders. Matches Redmine's
     * Role.givable scope; the one definition every role picker (member
     * forms, custom-field visibility, saved-query visibility) reads.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeGivable(Builder $query): Builder
    {
        return $query->whereNull('builtin')->orderBy('position');
    }

    /**
     * Redmine's Role#check_deletable (before_destroy): a builtin role, or
     * one still held by any member — directly, through a group or inherited
     * from a parent project — cannot be deleted, so deleting a role never
     * leaves members without roles. The roles screen says why; this is the
     * backstop.
     */
    protected static function booted(): void
    {
        self::deleting(function (Role $role): void {
            if (! $role->isDeletable()) {
                throw new LogicException('Cannot delete a builtin role or a role in use.');
            }
        });
    }

    public function isDeletable(): bool
    {
        return $this->builtin === null && ! $this->members()->exists();
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

    /**
     * $permissions plus the keys this role holds that no loaded code
     * registers — a disabled plugin's permissions (A12-03). They grant
     * nothing while unregistered, but saving the role from a screen that
     * can't show them must not drop them, so re-enabling the plugin brings
     * the grants back.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    public function withUnregisteredPermissions(array $permissions): array
    {
        $registry = app(PermissionRegistry::class);
        $unregistered = array_filter($this->permissionKeys(), fn (string $key) => ! $registry->has($key));

        return array_values(array_unique([...$permissions, ...$unregistered]));
    }

    /**
     * The issue permissions that can be limited to some trackers — the
     * columns of Redmine's roles/_form.html.erb tracker table.
     *
     * @return list<string>
     */
    public static function trackerPermissionKeys(): array
    {
        return ['view_issues', 'add_issues', 'edit_issues', 'add_issue_notes', 'delete_issues'];
    }

    /**
     * Redmine's Role#permissions_all_trackers?: false when the role lacks
     * the permission, otherwise true unless the permission was explicitly
     * limited to selected trackers (a missing setting means all trackers).
     */
    public function permissionsAllTrackers(string $permission): bool
    {
        if (! $this->hasPermission($permission)) {
            return false;
        }

        return ($this->settings['permissions_all_trackers'][$permission] ?? true) !== false;
    }

    /**
     * The trackers explicitly selected for the permission (Redmine's
     * Role#permissions_tracker_ids(permission)); only meaningful when
     * permissionsAllTrackers() is false.
     *
     * @return list<int>
     */
    public function permissionTrackerIds(string $permission): array
    {
        return array_values(array_map('intval', (array) ($this->settings['permissions_tracker_ids'][$permission] ?? [])));
    }

    /**
     * The trackers the role grants the permission on: null for all
     * trackers, a (possibly empty) list otherwise — empty as well when the
     * role doesn't hold the permission at all.
     *
     * @return list<int>|null
     */
    public function trackerIdsFor(string $permission): ?array
    {
        if (! $this->hasPermission($permission)) {
            return [];
        }

        return $this->permissionsAllTrackers($permission) ? null : $this->permissionTrackerIds($permission);
    }

    /**
     * Redmine's Role#permissions_tracker?: the role grants the permission on
     * this tracker, explicitly or through "all trackers".
     */
    public function allowsPermissionOnTracker(string $permission, int $trackerId): bool
    {
        $trackerIds = $this->trackerIdsFor($permission);

        return $trackerIds === null || in_array($trackerId, $trackerIds, true);
    }

    /**
     * Redmine's Role#set_permission_trackers: null for all trackers,
     * otherwise the selected tracker ids. Not saved.
     *
     * @param  array<int|string>|null  $trackerIds
     */
    public function setPermissionTrackers(string $permission, ?array $trackerIds): static
    {
        $settings = $this->settings ?? [];
        $settings['permissions_all_trackers'][$permission] = $trackerIds === null;
        $settings['permissions_tracker_ids'][$permission] = $trackerIds === null
            ? []
            : array_values(array_unique(array_map('intval', $trackerIds)));
        $this->settings = $settings;

        return $this;
    }
}
