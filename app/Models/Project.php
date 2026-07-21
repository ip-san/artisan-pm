<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Enums\CustomizableType;
use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Kalnoy\Nestedset\NodeTrait;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'identifier', 'description', 'is_public', 'parent_id'])]
final class Project extends Model implements HasMedia
{
    /** @use HasFactory<ProjectFactory> */
    use HasCustomFields, HasFactory, InteractsWithMedia, NodeTrait;

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'status' => ProjectStatus::class,
        ];
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'members')
            ->withTimestamps();
    }

    /**
     * Members eligible to be picked as an issue's assignee — those
     * holding at least one role with `assignable = true`. Only considers
     * direct user memberships, not roles gained through a group's own
     * project membership, unlike AuthorizationService's permission
     * resolution — a reasonable simplification since this only narrows
     * an assignee dropdown, not an access-control decision.
     *
     * @return Collection<int, User>
     */
    public function assignableUsers(): Collection
    {
        return $this->users()
            ->whereHas('memberships', function (Builder $query): void {
                $query->where('project_id', $this->id)
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('assignable', true));
            })
            ->get();
    }

    /**
     * @return HasMany<ProjectModuleAssignment, $this>
     */
    public function moduleAssignments(): HasMany
    {
        return $this->hasMany(ProjectModuleAssignment::class);
    }

    /**
     * @return BelongsToMany<Tracker, $this>
     */
    public function trackers(): BelongsToMany
    {
        return $this->belongsToMany(Tracker::class, 'project_tracker');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return HasMany<IssueCategory, $this>
     */
    public function issueCategories(): HasMany
    {
        return $this->hasMany(IssueCategory::class);
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<WikiPage, $this>
     */
    public function wikiPages(): HasMany
    {
        return $this->hasMany(WikiPage::class);
    }

    /**
     * @return HasMany<Board, $this>
     */
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class);
    }

    /**
     * @return HasMany<News, $this>
     */
    public function news(): HasMany
    {
        return $this->hasMany(News::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasOne<Repository, $this>
     */
    public function repository(): HasOne
    {
        return $this->hasOne(Repository::class);
    }

    public function hasModule(ProjectModuleKey $module): bool
    {
        return $this->moduleAssignments->contains('module', $module);
    }

    /**
     * @param  array<ProjectModuleKey>  $modules
     */
    public function syncModules(array $modules): void
    {
        $this->moduleAssignments()->delete();

        foreach ($modules as $module) {
            $this->moduleAssignments()->create(['module' => $module]);
        }

        $this->unsetRelation('moduleAssignments');
    }

    public function isOpen(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }

    public function isClosed(): bool
    {
        return $this->status === ProjectStatus::Closed;
    }

    public function isBookmarkedBy(User $user): bool
    {
        return $user->bookmarkedProjects()->where('projects.id', $this->id)->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files');
    }

    /**
     * Project-level files — not tied to any Version, unlike Version::files().
     *
     * @return MediaCollection<int, Media>
     */
    public function files(): MediaCollection
    {
        return $this->getMedia('files');
    }

    public static function customizableType(): CustomizableType
    {
        return CustomizableType::Project;
    }

    /**
     * All project-type custom fields, filtered to the ones visible to the
     * viewing user's role(s) in this project — admins see everything, and
     * a field with no role restriction is visible to anyone. There's no
     * tracker/project scoping here (unlike Issue) since these fields
     * describe the project itself rather than something within it.
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        $fields = CustomField::query()
            ->where('customized_type', CustomizableType::Project)
            ->with('roles')
            ->orderBy('position')
            ->get();

        $user = auth()->user();

        if ($user?->is_admin) {
            return $fields->values();
        }

        $userRoles = $user ? app(AuthorizationService::class)->rolesFor($user, $this) : collect();

        return $fields->filter(fn (CustomField $field) => $field->visibleToRoles($userRoles))->values();
    }
}
