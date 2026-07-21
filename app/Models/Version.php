<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VersionStatus;
use Database\Factories\VersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['project_id', 'name', 'description', 'status', 'due_date'])]
final class Version extends Model implements HasMedia
{
    /** @use HasFactory<VersionFactory> */
    use HasFactory, InteractsWithMedia;

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
    ];

    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files');
    }

    /**
     * @return MediaCollection<int, Media>
     */
    public function files(): MediaCollection
    {
        return $this->getMedia('files');
    }
}
