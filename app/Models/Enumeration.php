<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use Database\Factories\EnumerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

#[Fillable(['type', 'name', 'position', 'is_default', 'active', 'project_id', 'parent_id'])]
final class Enumeration extends Model implements Sortable
{
    /** @use HasFactory<EnumerationFactory> */
    use HasCustomFields, HasFactory, SortableTrait;

    /** @var array{order_column_name: string, sort_when_creating: bool} */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => EnumerationType::class,
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return Builder<Enumeration>
     */
    public function buildSortQuery(): Builder
    {
        return self::query()->where('type', $this->type)->where('project_id', $this->project_id);
    }

    /**
     * @param  Builder<Enumeration>  $query
     * @return Builder<Enumeration>
     */
    public function scopeOfType(Builder $query, EnumerationType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The project this override applies to — only set on a project-specific
     * override row (`parent_id` also set); global enumerations have neither.
     * Only meaningful for TimeEntryActivity, matching Redmine's own scope
     * for project-level enumeration overrides.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The global enumeration this project-specific row overrides.
     *
     * @return BelongsTo<Enumeration, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Only meaningful when type is IssuePriority.
     *
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'priority_id');
    }

    /**
     * Only meaningful when type is TimeEntryActivity.
     *
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'activity_id');
    }

    /**
     * Setting is_default clears every other enumeration of the same type,
     * matching Redmine's own "only one default per type" behavior — a
     * plain boolean column can't enforce this at the schema level.
     */
    public function makeDefault(): void
    {
        self::query()->where('type', $this->type)->where('id', '!=', $this->id)->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Only meaningful for TimeEntryActivity/DocumentCategory — matches
     * Redmine's TimeEntryActivityCustomField/DocumentCategoryCustomField
     * (IssuePriority has no custom-field equivalent in Redmine). This
     * model represents all three enumeration kinds in one table, so
     * unlike Issue/Project/Version/Group there's no single static "this
     * model's type"; treated as an implementation detail since nothing
     * else in the app actually calls this abstract trait method today.
     */
    public static function customizableType(): CustomizableType
    {
        return CustomizableType::TimeEntryActivity;
    }

    /**
     * TimeEntryActivity/DocumentCategory custom fields are relevant to
     * every enumeration of that same type — same reasoning as Group:
     * these are admin-only resources (EnumerationPolicy denies everyone
     * else) with no project/role visibility concept to filter by.
     * IssuePriority enumerations never have custom fields.
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        $customizableType = match ($this->type) {
            EnumerationType::TimeEntryActivity => CustomizableType::TimeEntryActivity,
            EnumerationType::DocumentCategory => CustomizableType::DocumentCategory,
            default => null,
        };

        if ($customizableType === null) {
            return collect();
        }

        return CustomField::query()
            ->where('customized_type', $customizableType)
            ->orderBy('position')
            ->get();
    }
}
