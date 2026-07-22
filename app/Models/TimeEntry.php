<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TimeEntryVisibility;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable(['project_id', 'issue_id', 'user_id', 'activity_id', 'hours', 'spent_on', 'comments'])]
final class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'spent_on' => 'date',
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
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Enumeration, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'activity_id');
    }

    /**
     * Cross-project variant of the project-scoped time entries list's own
     * visibility check — buckets $projects by each one's own
     * timeEntryVisibilityFor() outcome (All or Own; Default never occurs,
     * see AuthorizationService::timeEntryVisibilityFor()) and applies the
     * matching WHERE per bucket, so "all entries" in one project and "own
     * entries only" in another are both honored in a single query.
     * $projects is expected to already be pre-filtered to ones the user
     * can view time entries in at all (Project policy viewAny).
     *
     * @param  Builder<TimeEntry>  $query
     * @param  Collection<int, Project>  $projects
     * @return Builder<TimeEntry>
     */
    public function scopeVisibleToAcrossProjects(Builder $query, ?User $user, Collection $projects): Builder
    {
        if ($projects->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $authorization = app(AuthorizationService::class);
        $userId = $user?->id;

        $byTier = $projects->groupBy(
            fn (Project $project) => $authorization->timeEntryVisibilityFor($user, $project)->value
        );

        $allIds = $byTier->get(TimeEntryVisibility::All->value, collect())->pluck('id');
        $ownIds = $byTier->get(TimeEntryVisibility::Own->value, collect())->pluck('id');

        return $query->where(function (Builder $outer) use ($allIds, $ownIds, $userId): void {
            if ($allIds->isNotEmpty()) {
                $outer->orWhereIn('project_id', $allIds);
            }

            if ($ownIds->isNotEmpty()) {
                $outer->orWhere(fn ($q) => $q->whereIn('project_id', $ownIds)->where('user_id', $userId));
            }
        });
    }
}
