<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrackerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

#[Fillable(['name', 'description', 'position', 'default_status_id', 'disabled_core_fields', 'private_by_default'])]
final class Tracker extends Model implements Sortable
{
    /** @use HasFactory<TrackerFactory> */
    use HasFactory, SortableTrait;

    /**
     * Core issue fields a tracker may hide entirely — matches Redmine's
     * Tracker::CORE_FIELDS. project_id/tracker_id/subject/is_private are
     * deliberately excluded, matching Redmine's CORE_FIELDS_UNDISABLABLE.
     *
     * @var array<string, string>
     */
    public const array DISABLABLE_CORE_FIELDS = [
        'assigned_to_id' => '担当者',
        'category_id' => 'カテゴリ',
        'fixed_version_id' => '対象バージョン',
        'parent_id' => '親課題',
        'start_date' => '開始日',
        'due_date' => '期日',
        'estimated_hours' => '予定工数',
        'done_ratio' => '進捗率',
        'description' => '説明',
        'priority_id' => '優先度',
    ];

    /** @var array{order_column_name: string, sort_when_creating: bool} */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare private_by_default's here
     * too — otherwise a just-created Tracker's in-memory value is null
     * even though the `trackers` table default is false (same issue
     * already worked around on Issue/Version for their own defaulted
     * columns).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'private_by_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'disabled_core_fields' => 'array',
            'private_by_default' => 'boolean',
        ];
    }

    public function isCoreFieldDisabled(string $field): bool
    {
        return in_array($field, $this->disabled_core_fields ?? [], true);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_tracker');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function defaultStatus(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'default_status_id');
    }
}
