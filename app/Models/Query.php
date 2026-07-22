<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name', 'type', 'user_id', 'project_id', 'visibility',
    'filters', 'column_names', 'sort_criteria', 'group_by',
])]
final class Query extends Model
{
    protected function casts(): array
    {
        return [
            'type' => QueryType::class,
            'visibility' => QueryVisibility::class,
            'filters' => 'array',
            'column_names' => 'array',
            'sort_criteria' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Only meaningful when visibility is Roles — matches Redmine's
     * queries_roles pivot.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'query_role');
    }

    /**
     * Matches Redmine's Query#visible?, including anonymous visitors
     * (nullable $user — a public project's issue list can be reachable
     * without logging in). The Roles case falls back to denying access
     * for a project-less (cross-project) query — this app doesn't have
     * any of those yet, every saved query is created scoped to a
     * project.
     */
    public function visibleTo(?User $user): bool
    {
        if ($user?->is_admin || ($user !== null && $this->user_id === $user->id)) {
            return true;
        }

        return match ($this->visibility) {
            QueryVisibility::Public => true,
            QueryVisibility::Roles => $this->project !== null
                && app(AuthorizationService::class)->rolesFor($user, $this->project)
                    ->pluck('id')->intersect($this->roles->pluck('id'))->isNotEmpty(),
            QueryVisibility::Private => false,
        };
    }
}
