<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Issues\AssigneeChoice;
use Database\Factories\IssueCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'name', 'assigned_to_id', 'assigned_to_group_id'])]
final class IssueCategory extends Model
{
    /** @use HasFactory<IssueCategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        self::saving(fn (IssueCategory $category) => AssigneeChoice::keepSingle($category, 'assigned_to_id', 'assigned_to_group_id'));
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
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * A group default assignee (Redmine's IssueCategory#assigned_to is a
     * Principal), set instead of assigned_to_id.
     *
     * @return BelongsTo<Group, $this>
     */
    public function assignedToGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'assigned_to_group_id');
    }

    /**
     * The assignee a new issue in this category starts with (Redmine's
     * Issue#default_assign), when it can still be assigned in the project:
     * a member with an assignable role, or an assignable group while group
     * assignment is on.
     *
     * @return array{assigned_to_id: ?int, assigned_to_group_id: ?int}|null
     */
    public function usableDefaultAssignee(): ?array
    {
        if ($this->assigned_to_group_id !== null) {
            return AssigneeChoice::allowsGroup($this->project, $this->assigned_to_group_id)
                ? ['assigned_to_id' => null, 'assigned_to_group_id' => $this->assigned_to_group_id]
                : null;
        }

        return $this->assigned_to_id !== null
            ? ['assigned_to_id' => $this->assigned_to_id, 'assigned_to_group_id' => null]
            : null;
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'category_id');
    }
}
