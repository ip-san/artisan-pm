<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'member_roles')->withTimestamps();
    }

    public function isForGroup(): bool
    {
        return $this->group_id !== null;
    }
}
