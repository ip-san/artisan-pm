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
use Illuminate\Support\Collection;

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
     *
     * @param  Collection<int, int>|null  $userRoleIds  the viewer's role ids for this query's project, when already resolved by the caller (visibleIn() passes them so a listing doesn't re-resolve per row)
     */
    public function visibleTo(?User $user, ?Collection $userRoleIds = null): bool
    {
        if ($user?->is_admin || ($user !== null && $this->user_id === $user->id)) {
            return true;
        }

        return match ($this->visibility) {
            QueryVisibility::Public => true,
            QueryVisibility::Roles => $this->project !== null
                && ($userRoleIds ?? app(AuthorizationService::class)->rolesFor($user, $this->project)->pluck('id'))
                    ->intersect($this->roles->pluck('id'))->isNotEmpty(),
            QueryVisibility::Private => false,
        };
    }

    /**
     * The saved queries of $type a user should see listed on $project —
     * matches Redmine's Query.visible scope. Other users' private
     * queries are excluded in SQL (they can never pass visibleTo(), for
     * admins too — Redmine's visible scope has the same admin behavior,
     * even though direct access via visible? allows them); the
     * roles-scoped check that can't be expressed as one SQL predicate
     * runs in memory with the viewer's roles resolved once.
     *
     * @return Collection<int, Query>
     */
    public static function visibleIn(Project $project, QueryType $type, ?User $user): Collection
    {
        $userRoleIds = app(AuthorizationService::class)->rolesFor($user, $project)->pluck('id');

        return self::query()
            ->where('project_id', $project->id)
            ->where('type', $type->value)
            ->where(function ($q) use ($user) {
                $q->where('visibility', '<>', QueryVisibility::Private->value);

                if ($user !== null) {
                    $q->orWhere('user_id', $user->id);
                }
            })
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->each(fn (Query $query) => $query->setRelation('project', $project))
            ->filter(fn (Query $query) => $query->visibleTo($user, $userRoleIds))
            ->values();
    }

    /**
     * The visibility to actually persist for a save request — only a
     * manage_public_queries holder can make a query anything but
     * private. Matches Redmine's QueriesController#new/#create, which
     * silently forces VISIBILITY_PRIVATE for anyone else rather than
     * rejecting the submission outright.
     */
    public static function resolveVisibility(?User $user, string $requested, Project $project): string
    {
        return app(AuthorizationService::class)->can($user, 'manage_public_queries', $project)
            ? $requested
            : QueryVisibility::Private->value;
    }
}
