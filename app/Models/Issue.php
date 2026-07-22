<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Concerns\HasThumbnails;
use App\Enums\CustomizableType;
use App\Enums\IssueRelationType;
use App\Enums\IssueVisibility;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'project_id', 'tracker_id', 'status_id', 'priority_id', 'author_id',
    'assigned_to_id', 'fixed_version_id', 'parent_id', 'category_id', 'subject',
    'description', 'start_date', 'due_date', 'done_ratio', 'estimated_hours', 'is_private',
])]
final class Issue extends Model implements HasMedia
{
    /** @use HasFactory<IssueFactory> */
    use HasCustomFields, HasFactory, HasThumbnails, InteractsWithMedia, Searchable {
        HasThumbnails::registerMediaConversions insteadof InteractsWithMedia;
    }

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare done_ratio's default here too
     * — otherwise a just-created Issue's in-memory done_ratio is null even
     * though the `issues` table default is 0.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'done_ratio' => 0,
        'is_private' => false,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'done_ratio' => 'integer',
            'estimated_hours' => 'decimal:2',
            'closed_on' => 'datetime',
            'is_private' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Tracker, $this>
     */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<Enumeration, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'priority_id');
    }

    /**
     * @return BelongsTo<IssueCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IssueCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return BelongsTo<Version, $this>
     */
    public function fixedVersion(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'fixed_version_id');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'parent_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Issue::class, 'parent_id');
    }

    /**
     * @return HasMany<IssueRelation, $this>
     */
    public function relationsFrom(): HasMany
    {
        return $this->hasMany(IssueRelation::class, 'issue_from_id');
    }

    /**
     * @return HasMany<IssueRelation, $this>
     */
    public function relationsTo(): HasMany
    {
        return $this->hasMany(IssueRelation::class, 'issue_to_id');
    }

    /**
     * @return HasMany<Journal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return MorphMany<Watcher, $this>
     */
    public function watchers(): MorphMany
    {
        return $this->morphMany(Watcher::class, 'watchable');
    }

    /**
     * @return BelongsToMany<Changeset, $this>
     */
    public function changesets(): BelongsToMany
    {
        return $this->belongsToMany(Changeset::class);
    }

    public function isWatchedBy(User $user): bool
    {
        return $this->watchers->contains('user_id', $user->id);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * @return MediaCollection<int, Media>
     */
    public function attachments(): MediaCollection
    {
        return $this->getMedia('attachments');
    }

    public function isClosed(): bool
    {
        return $this->status->is_closed;
    }

    /**
     * True when another still-open issue blocks this one (a relation
     * where this issue is the `to` side with type 'blocks') — matches
     * Redmine's Issue#blocked?.
     */
    public function isBlocked(): bool
    {
        return $this->relationsTo()
            ->where('relation_type', IssueRelationType::Blocks->value)
            ->whereHas('from.status', fn ($query) => $query->where('is_closed', false))
            ->exists();
    }

    public function hasOpenChildren(): bool
    {
        return $this->children()->whereHas('status', fn ($query) => $query->where('is_closed', false))->exists();
    }

    /**
     * Whether this issue can transition to a closed status — false when
     * blocked by an open issue or has open subtasks, matching Redmine's
     * Issue#closable?.
     */
    public function isClosable(): bool
    {
        return ! $this->isBlocked() && ! $this->hasOpenChildren();
    }

    /**
     * Whether this issue can transition to an open status — false when
     * any ancestor (not just the immediate parent) currently has a
     * closed status, matching Redmine's Issue#reopenable?.
     */
    public function isReopenable(): bool
    {
        $parentId = $this->parent_id;

        while ($parentId !== null) {
            $parent = self::query()->with('status')->find($parentId);

            if ($parent === null) {
                return true;
            }

            if ($parent->status->is_closed) {
                return false;
            }

            $parentId = $parent->parent_id;
        }

        return true;
    }

    /**
     * Issues that duplicate this one — the `from` side of any 'duplicates'
     * relation where this issue is the `to` side, matching Redmine's
     * Issue#duplicates ("issue_from duplicates issue_to").
     *
     * @return Collection<int, Issue>
     */
    public function duplicates(): Collection
    {
        return $this->relationsTo()
            ->where('relation_type', IssueRelationType::Duplicates->value)
            ->with('from')
            ->get()
            ->map(fn (IssueRelation $relation) => $relation->from);
    }

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /**
     * Every descendant issue's id (children, grandchildren, ...) via a
     * recursive CTE — Issue's hierarchy is an adjacency list (parent_id),
     * the same structural reason GanttService uses raw SQL for the
     * whole-project tree; this is the single-issue equivalent.
     *
     * @return Collection<int, int>
     */
    public function descendantIds(): Collection
    {
        $table = $this->getTable();

        $rows = DB::select(<<<SQL
            WITH RECURSIVE descendants AS (
                SELECT id FROM {$table} WHERE parent_id = ?
                UNION ALL
                SELECT i.id FROM {$table} i INNER JOIN descendants d ON i.parent_id = d.id
            )
            SELECT id FROM descendants
            SQL, [$this->id]);

        return collect($rows)->pluck('id');
    }

    /**
     * Hours logged directly against this issue — matches Redmine's
     * Issue#spent_hours.
     */
    public function spentHours(): float
    {
        return (float) $this->timeEntries()->sum('hours');
    }

    /**
     * Hours logged against this issue and all of its descendants —
     * matches Redmine's Issue#total_spent_hours.
     */
    public function totalSpentHours(): float
    {
        if ($this->isLeaf()) {
            return $this->spentHours();
        }

        $ids = $this->descendantIds()->push($this->id);

        return (float) TimeEntry::query()->whereIn('issue_id', $ids)->sum('hours');
    }

    /**
     * The estimated_hours of this issue and all of its descendants summed
     * together — matches Redmine's Issue#total_estimated_hours.
     */
    public function totalEstimatedHours(): float
    {
        if ($this->isLeaf()) {
            return (float) ($this->estimated_hours ?? 0);
        }

        $ids = $this->descendantIds()->push($this->id);

        return (float) Issue::query()->whereIn('id', $ids)->sum('estimated_hours');
    }

    public static function customizableType(): CustomizableType
    {
        return CustomizableType::Issue;
    }

    /**
     * The custom fields relevant to this issue's tracker and project, further
     * narrowed to the ones visible to the current user's role(s) — admins,
     * and anyone when a field has no role restriction, see everything.
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        $fields = CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereHas('trackers', fn ($query) => $query->where('trackers.id', $this->tracker_id))
            ->with(['trackers', 'projects', 'roles'])
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $field->appliesToProject($this->project));

        $user = auth()->user();

        if ($user?->is_admin) {
            return $fields->values();
        }

        $userRoles = $user ? app(AuthorizationService::class)->rolesFor($user, $this->project) : collect();

        return $fields->filter(fn (CustomField $field) => $field->visibleToRoles($userRoles))->values();
    }

    /**
     * Narrows a query to the issues $user is allowed to see in $project,
     * per the project's configured issue visibility (all / default / own).
     * Shared by the issue list and My Page's saved-query blocks so the two
     * don't drift on what "visible" means.
     *
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function scopeVisibleTo(Builder $query, ?User $user, Project $project): Builder
    {
        $userId = $user?->id;

        return match (app(AuthorizationService::class)->issueVisibilityFor($user, $project)) {
            IssueVisibility::All => $query,
            IssueVisibility::Default => $query->where(fn ($q) => $q->where('is_private', false)
                ->orWhere('author_id', $userId)
                ->orWhere('assigned_to_id', $userId)),
            IssueVisibility::Own => $query->where(fn ($q) => $q->where('author_id', $userId)->orWhere('assigned_to_id', $userId)),
        };
    }

    /**
     * Cross-project variant of scopeVisibleTo() — a user can have "all
     * issues" visibility in one project and "own issues only" in
     * another, so $projects is bucketed by each project's own visibility
     * tier and each bucket gets its matching WHERE, rather than applying
     * one tier across every project. $projects is expected to already be
     * pre-filtered to ones the user can view at all (Project policy
     * viewAny) — this only adds the per-issue visibility tier within them.
     *
     * @param  Builder<Issue>  $query
     * @param  Collection<int, Project>  $projects
     * @return Builder<Issue>
     */
    public function scopeVisibleToAcrossProjects(Builder $query, ?User $user, Collection $projects): Builder
    {
        if ($projects->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $authorization = app(AuthorizationService::class);
        $userId = $user?->id;

        $byTier = $projects->groupBy(
            fn (Project $project) => $authorization->issueVisibilityFor($user, $project)->value
        );

        $allIds = $byTier->get(IssueVisibility::All->value, collect())->pluck('id');
        $defaultIds = $byTier->get(IssueVisibility::Default->value, collect())->pluck('id');
        $ownIds = $byTier->get(IssueVisibility::Own->value, collect())->pluck('id');

        return $query->where(function (Builder $outer) use ($allIds, $defaultIds, $ownIds, $userId): void {
            if ($allIds->isNotEmpty()) {
                $outer->orWhereIn('project_id', $allIds);
            }

            if ($defaultIds->isNotEmpty()) {
                $outer->orWhere(fn ($q) => $q->whereIn('project_id', $defaultIds)
                    ->where(fn ($q2) => $q2->where('is_private', false)
                        ->orWhere('author_id', $userId)
                        ->orWhere('assigned_to_id', $userId)));
            }

            if ($ownIds->isNotEmpty()) {
                $outer->orWhere(fn ($q) => $q->whereIn('project_id', $ownIds)
                    ->where(fn ($q2) => $q2->where('author_id', $userId)->orWhere('assigned_to_id', $userId)));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'subject' => $this->subject,
            'description' => $this->description,
        ];
    }
}
