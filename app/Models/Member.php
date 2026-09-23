<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MemberInheritance;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

#[Fillable(['project_id', 'user_id', 'group_id'])]
final class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mail_notification' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Redmine's Member#remove_from_project_default_assigned_to: a user who
        // leaves the project can no longer be its default assignee.
        self::deleted(function (Member $member) {
            if ($member->user_id !== null) {
                Project::query()
                    ->whereKey($member->project_id)
                    ->where('default_assigned_to_id', $member->user_id)
                    ->update(['default_assigned_to_id' => null]);
            }
        });

        // The member's roles are gone (member_roles cascades, and so do the
        // copies subprojects inherited from them): drop the copies' members
        // that are left without a role.
        self::deleted(function (Member $member) {
            app(MemberInheritance::class)->syncChildren($member->project_id);
        });

        self::saving(function (Member $member) {
            if (($member->user_id === null) === ($member->group_id === null)) {
                throw new LogicException('A member must belong to exactly one of a user or a group.');
            }
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Every role the member holds, own and inherited alike. The pivot's
     * inherited_from is the parent project's member_roles row a role was
     * copied from ({@see MemberInheritance}), null for a role given here.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'member_roles')->withPivot('inherited_from')->withTimestamps();
    }

    /**
     * Whether any of the member's roles was inherited from the parent
     * project — Redmine's Member#any_inherited_role?, which makes the
     * member undeletable in this project.
     */
    public function hasInheritedRoles(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->pivot->inherited_from !== null);
    }

    /**
     * @return Collection<int, int>
     */
    public function inheritedRoleIds(): Collection
    {
        return $this->roles
            ->filter(fn (Role $role) => $role->pivot->inherited_from !== null)
            ->pluck('id')
            ->values();
    }

    /**
     * The roles given to the member in this project itself.
     *
     * @return Collection<int, int>
     */
    public function directRoleIds(): Collection
    {
        return $this->roles
            ->filter(fn (Role $role) => $role->pivot->inherited_from === null)
            ->pluck('id')
            ->values();
    }

    /**
     * Replaces the roles given in this project, never touching the
     * inherited ones — Redmine's Member#role_ids=, which keeps inherited
     * roles whatever is submitted. A role the member already inherits is
     * not added a second time. Subprojects inheriting from this project
     * are re-synced afterwards.
     *
     * @param  iterable<int|string>  $roleIds
     */
    public function syncDirectRoles(iterable $roleIds): void
    {
        $wantedRoleIds = collect($roleIds)->map(fn ($id) => (int) $id)->unique()->values();

        DB::transaction(function () use ($wantedRoleIds): void {
            DB::table('member_roles')
                ->where('member_id', $this->id)
                ->whereNull('inherited_from')
                ->whereNotIn('role_id', $wantedRoleIds)
                ->delete();

            $heldRoleIds = DB::table('member_roles')->where('member_id', $this->id)->pluck('role_id')->map(fn ($id) => (int) $id);

            $wantedRoleIds->diff($heldRoleIds)->each(fn (int $roleId) => DB::table('member_roles')->insert([
                'member_id' => $this->id,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            app(MemberInheritance::class)->sync($this->project);
        });

        $this->unsetRelation('roles');
    }

    public function isForGroup(): bool
    {
        return $this->group_id !== null;
    }
}
