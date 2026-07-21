<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Enums\CustomizableType;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'project_id', 'tracker_id', 'status_id', 'priority_id', 'author_id',
    'assigned_to_id', 'fixed_version_id', 'parent_id', 'subject',
    'description', 'start_date', 'due_date', 'done_ratio',
])]
final class Issue extends Model implements HasMedia
{
    /** @use HasFactory<IssueFactory> */
    use HasCustomFields, HasFactory, InteractsWithMedia;

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
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'done_ratio' => 'integer',
            'closed_on' => 'datetime',
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
        return $this->hasMany(Journal::class)->orderBy('created_at');
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
}
