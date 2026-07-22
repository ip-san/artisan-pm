<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Concerns\HasThumbnails;
use App\Enums\CustomizableType;
use App\Enums\VersionSharing;
use App\Enums\VersionStatus;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\VersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['project_id', 'name', 'description', 'status', 'sharing', 'due_date', 'wiki_page_title'])]
final class Version extends Model implements HasMedia
{
    /** @use HasFactory<VersionFactory> */
    use HasCustomFields, HasFactory, HasThumbnails, InteractsWithMedia {
        HasThumbnails::registerMediaConversions insteadof InteractsWithMedia;
    }

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare status's default here too —
     * otherwise a just-created Version's in-memory status is null even
     * though the `versions` table default is 'open' (same issue already
     * worked around on Issue for done_ratio/is_private/lock_version).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'sharing' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'sharing' => VersionSharing::class,
            'due_date' => 'date',
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
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'fixed_version_id');
    }

    public static function customizableType(): CustomizableType
    {
        return CustomizableType::Version;
    }

    /**
     * Which sharing levels $user is allowed to set on this version —
     * matches Redmine's Version#allowed_sharings. The version's current
     * sharing is always allowed (so an edit form never silently drops
     * it); system-wide sharing needs admin, and hierarchy/tree need
     * manage_versions on the root of the version's project tree (so a
     * sub-project maintainer can't quietly widen a version's reach up
     * the tree).
     *
     * @return array<int, VersionSharing>
     */
    public function allowedSharings(?User $user): array
    {
        $authorization = app(AuthorizationService::class);
        $root = $this->project?->rootProject();

        return array_values(array_filter(VersionSharing::cases(), function (VersionSharing $sharing) use ($user, $authorization, $root) {
            if ($this->sharing === $sharing) {
                return true;
            }

            return match ($sharing) {
                VersionSharing::System => (bool) $user?->is_admin,
                VersionSharing::Hierarchy, VersionSharing::Tree => $this->project === null
                    || ($root !== null && $authorization->can($user, 'manage_versions', $root)),
                default => true,
            };
        }));
    }

    /**
     * Versions have no roles/members of their own, so visibility is
     * resolved through the owning project's roles, same as Project's own
     * relevantCustomFields() delegates to AuthorizationService::rolesFor().
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        $fields = CustomField::query()
            ->where('customized_type', CustomizableType::Version)
            ->with('roles')
            ->orderBy('position')
            ->get();

        $user = auth()->user();

        if ($user?->is_admin) {
            return $fields->values();
        }

        $userRoles = $user ? app(AuthorizationService::class)->rolesFor($user, $this->project) : collect();

        return $fields->filter(fn (CustomField $field) => $field->visibleToRoles($userRoles))->values();
    }

    /**
     * Sum of estimated_hours across this version's leaf issues only —
     * matches Redmine's Version#estimated_hours ("sum of leaves
     * estimated_hours"). Issues with children are excluded so a parent
     * assigned to this version doesn't double-count time already
     * reflected in its children's own estimates.
     */
    public function estimatedHours(): float
    {
        return (float) $this->issues()->whereDoesntHave('children')->sum('estimated_hours');
    }

    /**
     * Sum of estimated remaining hours (estimated_hours * (100 -
     * done_ratio) / 100) across this version's leaf issues — matches
     * Redmine's Version#estimated_remaining_hours.
     */
    public function estimatedRemainingHours(): float
    {
        return $this->issues()
            ->whereDoesntHave('children')
            ->get(['estimated_hours', 'done_ratio'])
            ->sum(fn (Issue $issue) => ((float) ($issue->estimated_hours ?? 0)) * (100 - $issue->done_ratio) / 100);
    }

    /**
     * Sum of TimeEntry hours logged against any issue fixed to this
     * version — matches Redmine's Version#spent_hours. Not leaf-restricted
     * (unlike estimated hours) since time entries themselves are never
     * double-counted regardless of hierarchy.
     */
    public function spentHours(): float
    {
        return (float) TimeEntry::query()
            ->whereIn('issue_id', $this->issues()->pluck('id'))
            ->sum('hours');
    }

    /**
     * A version is complete once it's already closed, or its due date has
     * passed with no open issues remaining — matches Redmine's Version#
     * completed?. Used by Project::closeCompletedVersions() to decide
     * which open/locked versions to auto-close.
     */
    public function isCompleted(): bool
    {
        if ($this->status === VersionStatus::Closed) {
            return true;
        }

        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->issues()->whereHas('status', fn ($query) => $query->where('is_closed', false))->exists();
    }

    /**
     * Total issues fixed to this version, split by open/closed — matches
     * Redmine's Version#open_count/closed_count. Unlike estimatedHours()/
     * spentHours(), these count every issue (not leaves only): Redmine's
     * own fixed_issues association has no such restriction.
     *
     * @return array{open: int, closed: int}
     */
    public function issueCounts(): array
    {
        $closed = (int) $this->issues()->whereHas('status', fn ($query) => $query->where('is_closed', true))->count();
        $open = (int) $this->issues()->whereHas('status', fn ($query) => $query->where('is_closed', false))->count();

        return ['open' => $open, 'closed' => $closed];
    }

    /**
     * The percentage of this version's issues that are closed — matches
     * Redmine's Version#closed_percent.
     */
    public function closedPercent(): float
    {
        $counts = $this->issueCounts();
        $total = $counts['open'] + $counts['closed'];

        if ($total === 0) {
            return 0.0;
        }

        return round($counts['closed'] / $total * 100, 1);
    }

    /**
     * Overall completion, weighting each issue by its estimated hours
     * (unestimated issues use the average estimate among the ones that
     * have one, or 1.0 if none do) and treating closed issues as 100%
     * done — matches Redmine's Version#completed_percent /
     * issues_progress. Same weighting scheme as IssueService's parent-
     * issue done_ratio rollup.
     */
    public function completedPercent(): float
    {
        $issues = $this->issues()->get(['estimated_hours', 'done_ratio', 'status_id'])->load('status');

        if ($issues->isEmpty()) {
            return 0.0;
        }

        $withEstimates = $issues->filter(fn (Issue $issue) => (float) ($issue->estimated_hours ?? 0) > 0.0);
        $averageEstimate = $withEstimates->isNotEmpty()
            ? $withEstimates->sum(fn (Issue $issue) => (float) $issue->estimated_hours) / $withEstimates->count()
            : 1.0;

        $weightedSum = $issues->sum(function (Issue $issue) use ($averageEstimate) {
            $estimate = (float) ($issue->estimated_hours ?? 0) > 0.0 ? (float) $issue->estimated_hours : $averageEstimate;
            $ratio = $issue->status->is_closed ? 100 : $issue->done_ratio;

            return $estimate * $ratio;
        });

        return round($weightedSum / ($averageEstimate * $issues->count()), 1);
    }

    /**
     * The wiki page this version links to, resolved by title within its
     * own project's wiki — matches Redmine's Version#wiki_page, which
     * resolves wiki_page_title the same way rather than storing a foreign
     * key (wiki pages are identified by title, not id, throughout Redmine).
     */
    public function wikiPage(): ?WikiPage
    {
        if ($this->wiki_page_title === null) {
            return null;
        }

        return $this->project->wikiPages()->where('title', $this->wiki_page_title)->first();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files');
    }

    /**
     * @return array<int, string>
     */
    protected function thumbnailCollections(): array
    {
        return ['files'];
    }

    /**
     * @return MediaCollection<int, Media>
     */
    public function files(): MediaCollection
    {
        return $this->getMedia('files');
    }
}
