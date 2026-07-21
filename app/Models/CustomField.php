<?php

declare(strict_types=1);

namespace App\Models;

use App\CustomFields\FormatRegistry;
use App\CustomFields\Formats\FormatContract;
use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

#[Fillable([
    'name', 'field_format', 'customized_type', 'is_required', 'multiple',
    'searchable', 'default_value', 'min_length', 'max_length', 'regexp',
    'possible_values', 'position',
])]
final class CustomField extends Model implements Sortable
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory, SortableTrait;

    /** @var array{order_column_name: string, sort_when_creating: bool} */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model, so declare these here too — otherwise
     * a just-created CustomField's in-memory booleans are null even
     * though their table defaults are false (same issue already worked
     * around elsewhere in this app for other models' defaulted columns).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_required' => false,
        'multiple' => false,
        'searchable' => false,
    ];

    protected function casts(): array
    {
        return [
            'field_format' => CustomFieldFormat::class,
            'customized_type' => CustomizableType::class,
            'is_required' => 'boolean',
            'multiple' => 'boolean',
            'searchable' => 'boolean',
            'possible_values' => 'array',
        ];
    }

    /**
     * @return Builder<CustomField>
     */
    public function buildSortQuery(): Builder
    {
        return self::query()->where('customized_type', $this->customized_type);
    }

    /**
     * @return BelongsToMany<Tracker, $this>
     */
    public function trackers(): BelongsToMany
    {
        return $this->belongsToMany(Tracker::class, 'custom_field_tracker');
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'custom_field_project');
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'custom_field_role');
    }

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function format(): FormatContract
    {
        return app(FormatRegistry::class)->get($this->field_format);
    }

    public function appliesToTracker(Tracker $tracker): bool
    {
        return $this->trackers->contains('id', $tracker->id);
    }

    /**
     * Empty pivot means "applies to every project", matching Redmine.
     */
    public function appliesToProject(Project $project): bool
    {
        return $this->projects->isEmpty() || $this->projects->contains('id', $project->id);
    }

    /**
     * Empty pivot means "visible regardless of role", matching Redmine.
     *
     * @param  Collection<int, Role>  $userRoles
     */
    public function visibleToRoles(Collection $userRoles): bool
    {
        return $this->roles->isEmpty() || $this->roles->pluck('id')->intersect($userRoles->pluck('id'))->isNotEmpty();
    }
}
