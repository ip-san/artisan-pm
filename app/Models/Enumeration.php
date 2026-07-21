<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnumerationType;
use Database\Factories\EnumerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

#[Fillable(['type', 'name', 'position', 'is_default', 'active'])]
final class Enumeration extends Model implements Sortable
{
    /** @use HasFactory<EnumerationFactory> */
    use HasFactory, SortableTrait;

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
        return self::query()->where('type', $this->type);
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
}
