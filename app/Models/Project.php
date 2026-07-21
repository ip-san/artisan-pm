<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kalnoy\Nestedset\NodeTrait;

#[Fillable(['name', 'identifier', 'description', 'is_public', 'parent_id'])]
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, NodeTrait;

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
}
