<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Concerns\HasThumbnails;
use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use App\Enums\VersionSharing;
use App\Enums\VersionStatus;
use App\Services\VersionService;
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
    use HasCustomFields, HasFactory, HasThumbnails, InteractsWithMedia, NodeTrait {
        HasThumbnails::registerMediaConversions insteadof InteractsWithMedia;
    }

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
     * The root of this project's tree (itself, if it's already a root).
     * kalnoy/nestedset has no direct root accessor, so this walks to the
     * topmost ancestor by `_lft`.
     */
    public function rootProject(): self
    {
        if ($this->isRoot()) {
            return $this;
        }

        // An ancestor spans this node (_lft < this._lft AND _rgt >
        // this._rgt); the topmost one (smallest _lft) is the tree root.
        // kalnoy's own ancestors()/defaultOrder() helpers would express
        // this, but they live on its custom relation/builder classes that
        // PHPStan can't see through — the plain-column query keeps static
        // analysis intact.
        return self::query()
            ->where('_lft', '<', $this->_lft)
            ->where('_rgt', '>', $this->_rgt)
            ->orderBy('_lft')
            ->first() ?? $this;
    }

    /**
     * Every version assignable to issues in this project — its own plus
     * any shared version whose sharing scope reaches here. Ports
     * Redmine's Project#shared_versions, expressed against the same
     * nested-set columns (kalnoy/nestedset's `_lft`/`_rgt`):
     *
     *  - the project's own versions, regardless of sharing;
     *  - `system` versions from any non-archived project;
     *  - `tree` versions from any project sharing this project's root;
     *  - `descendants`/`hierarchy` versions on an ancestor of this project;
     *  - `hierarchy` versions on a descendant of this project.
     *
     * A brand-new (unsaved) project has no place in the tree yet, so only
     * `system` versions are reachable — matching Redmine's new_record?
     * branch.
     *
     * @return Collection<int, Version>
     */
    public function sharedVersions(): Collection
    {
        $query = Version::query()->join('projects', 'versions.project_id', '=', 'projects.id')
            ->with('project')
            ->select('versions.*');

        if (! $this->exists) {
            return $query
                ->where('projects.status', '<>', ProjectStatus::Archived->value)
                ->where('versions.sharing', VersionSharing::System->value)
                ->get();
        }

        $root = $this->rootProject();

        return $query->where(function (Builder $outer) use ($root) {
            $outer->where('projects.id', $this->id)
                ->orWhere(function (Builder $shared) use ($root) {
                    $shared->where('projects.status', '<>', ProjectStatus::Archived->value)
                        ->where(function (Builder $scope) use ($root) {
                            $scope->where('versions.sharing', VersionSharing::System->value)
                                ->orWhere(fn (Builder $q) => $q
                                    ->where('projects._lft', '>=', $root->_lft)
                                    ->where('projects._rgt', '<=', $root->_rgt)
                                    ->where('versions.sharing', VersionSharing::Tree->value))
                                ->orWhere(fn (Builder $q) => $q
                                    ->where('projects._lft', '<', $this->_lft)
                                    ->where('projects._rgt', '>', $this->_rgt)
                                    ->whereIn('versions.sharing', [VersionSharing::Hierarchy->value, VersionSharing::Descendants->value]))
                                ->orWhere(fn (Builder $q) => $q
                                    ->where('projects._lft', '>', $this->_lft)
                                    ->where('projects._rgt', '<', $this->_rgt)
                                    ->where('versions.sharing', VersionSharing::Hierarchy->value));
                        });
                });
        })->get();
    }

    /**
     * Closes every open/locked version that's completed — matches
     * Redmine's Project#close_completed_versions, triggered by an explicit
     * admin action rather than automatically on every issue close.
     */
    public function closeCompletedVersions(): void
    {
        $this->versions()
            ->whereIn('status', [VersionStatus::Open->value, VersionStatus::Locked->value])
            ->get()
            ->each(function (Version $version) {
                if ($version->isCompleted()) {
                    app(VersionService::class)->update($version, ['status' => VersionStatus::Closed]);
                }
            });
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * This project's own TimeEntryActivity overrides — matches Redmine's
     * Project#time_entry_activities. Each row's parent_id points at the
     * global Enumeration it overrides; only its `active` flag differs
     * from the parent (Redmine's own project-activity form only ever
     * toggles active/inactive, never renames — see
     * Project#create_time_entry_activity_if_needed, which always copies
     * the parent's name).
     *
     * @return HasMany<Enumeration, $this>
     */
    public function timeEntryActivityOverrides(): HasMany
    {
        return $this->hasMany(Enumeration::class)->ofType(EnumerationType::TimeEntryActivity)->whereNotNull('parent_id');
    }

    /**
     * The effective set of TimeEntryActivity enumerations available to
     * this project: every global one except those this project has
     * overridden, plus this project's own override rows in their place —
     * matches Redmine's Project#activities.
     *
     * @return Collection<int, Enumeration>
     */
    public function activities(bool $includeInactive = false): Collection
    {
        $overriddenParentIds = $this->timeEntryActivityOverrides()->pluck('parent_id');

        $query = Enumeration::query()
            ->ofType(EnumerationType::TimeEntryActivity)
            ->where(fn (Builder $q) => $q->whereNull('project_id')->orWhere('project_id', $this->id));

        if ($overriddenParentIds->isNotEmpty()) {
            $query->whereNotIn('id', $overriddenParentIds);
        }

        if (! $includeInactive) {
            $query->where('active', true);
        }

        return $query->orderBy('position')->get();
    }

    /**
     * @return HasMany<WikiPage, $this>
     */
    public function wikiPages(): HasMany
    {
        return $this->hasMany(WikiPage::class);
    }

    /**
     * @return HasMany<WikiRedirect, $this>
     */
    public function wikiRedirects(): HasMany
    {
        return $this->hasMany(WikiRedirect::class);
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
     * @return array<int, string>
     */
    protected function thumbnailCollections(): array
    {
        return ['files'];
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
     * Matches Redmine's Project.next_identifier — the most recently
     * created project's identifier (by id, not alphabetically), advanced
     * by one. Redmine itself does this via Ruby's generic String#succ,
     * which increments the rightmost run of digits (carrying into more
     * digits on overflow, e.g. "project9" -> "project10") or, for a
     * string with no trailing digits, increments the last letter
     * (e.g. "projectz" -> "projectaa"). Only the digit-suffix case is
     * replicated here — the far rarer letter-carry case falls back to
     * appending "1", a simpler but still-sensible next value; a
     * deliberately scoped simplification since this only matters for
     * unusually-named projects to begin with.
     */
    public static function nextIdentifier(): ?string
    {
        $last = self::query()->orderByDesc('id')->first();

        if ($last === null) {
            return null;
        }

        if (preg_match('/^(.*?)(\d+)$/', $last->identifier, $matches) !== 1) {
            return $last->identifier.'1';
        }

        [, $prefix, $digits] = $matches;
        $incremented = (string) ((int) $digits + 1);

        if (strlen($incremented) < strlen($digits)) {
            $incremented = str_pad($incremented, strlen($digits), '0', STR_PAD_LEFT);
        }

        return $prefix.$incremented;
    }

    /**
     * The role auto-granted to a non-admin project creator — matches
     * Redmine's Project.default_member_role, resolved from the
     * new_project_user_role_id setting and falling back to the first
     * givable role (same fallback Redmine itself applies) so this is
     * never silently a no-op as long as at least one givable role exists.
     */
    public static function defaultMemberRole(): ?Role
    {
        $roleId = Setting::get('new_project_user_role_id');

        return Role::query()->givable()->find($roleId) ?? Role::query()->givable()->first();
    }

    /**
     * Matches Redmine's Project#add_default_member, called only for
     * non-admin creators (an admin already sees every project regardless
     * of membership). No-op when there is no givable role to grant at
     * all — an extremely unlikely empty-installation edge case, but a
     * memberless Member row with zero roles isn't a state this app's
     * membership model otherwise allows.
     */
    public function addDefaultMember(User $user): ?Member
    {
        $role = self::defaultMemberRole();

        if ($role === null) {
            return null;
        }

        $member = new Member(['project_id' => $this->id, 'user_id' => $user->id]);
        $member->save();
        $member->roles()->attach($role);

        return $member;
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
