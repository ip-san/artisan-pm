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
    'searchable', 'editable', 'default_value', 'min_length', 'max_length', 'regexp',
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
        'editable' => true,
    ];

    /**
     * Two invariants ported from Redmine's CustomField:
     *
     * - The field format is fixed at creation. Redmine's field_format=
     *   setter silently ignores assignment on a saved record ("cannot
     *   change format of a saved custom field") — values already stored
     *   under the old format's storage column/semantics would otherwise
     *   be misread or orphaned. Mirrored here by reverting any dirty
     *   field_format on update; the admin form also disables the
     *   selector, this is the backstop.
     *
     * - Turning `multiple` off on an existing field deletes all but the
     *   newest (highest-id) value per customized object — Redmine's
     *   handle_multiplicity_change.
     */
    protected static function booted(): void
    {
        self::updating(function (CustomField $field) {
            if ($field->isDirty('field_format')) {
                $field->field_format = $field->getOriginal('field_format');
            }
        });

        self::updated(function (CustomField $field) {
            if ($field->wasChanged('multiple') && ! $field->multiple) {
                $field->values()
                    ->whereExists(fn ($query) => $query
                        ->from('custom_field_values as newer')
                        ->whereColumn('newer.custom_field_id', 'custom_field_values.custom_field_id')
                        ->whereColumn('newer.customized_type', 'custom_field_values.customized_type')
                        ->whereColumn('newer.customized_id', 'custom_field_values.customized_id')
                        ->whereColumn('newer.id', '>', 'custom_field_values.id'))
                    ->delete();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'field_format' => CustomFieldFormat::class,
            'customized_type' => CustomizableType::class,
            'is_required' => 'boolean',
            'multiple' => 'boolean',
            'searchable' => 'boolean',
            'editable' => 'boolean',
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

    /**
     * @return HasMany<CustomFieldEnumeration, $this>
     */
    public function enumerationOptions(): HasMany
    {
        return $this->hasMany(CustomFieldEnumeration::class)->orderBy('position');
    }

    public function format(): FormatContract
    {
        return app(FormatRegistry::class)->get($this->field_format);
    }

    /**
     * Livewire validation rules for a form's customFieldValues property
     * covering $fields — the shared rule-building loop every
     * custom-field-capable form (issue/project/version/group/enumeration)
     * uses. $forceRequired lets the issue form add its workflow-driven
     * per-field requiredness on top of each field's own is_required.
     *
     * @param  Collection<int, CustomField>  $fields
     * @param  (callable(CustomField): bool)|null  $forceRequired
     * @return array<string, array<int, mixed>>
     */
    public static function formValidationRules(Collection $fields, ?callable $forceRequired = null): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $key = "customFieldValues.{$field->id}";
            $required = $field->is_required || ($forceRequired !== null && $forceRequired($field));
            $presence = $required ? 'required' : 'nullable';

            if ($field->multiple) {
                $rules[$key] = [$presence, 'array'];
                $rules["{$key}.*"] = $field->format()->validationRules($field);
            } else {
                $rules[$key] = [$presence, ...$field->format()->validationRules($field)];
            }
        }

        return $rules;
    }

    /**
     * Strips values for fields $user isn't allowed to edit — the
     * server-side backstop for the disabled state x-custom-field-input
     * renders client-side. A disabled HTML attribute is a rendering hint
     * only; it doesn't stop a hand-crafted Livewire request from setting
     * the property directly, so every save() path filters through this
     * right before persisting.
     *
     * @param  Collection<int, CustomField>  $fields
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    public static function filterEditableValues(Collection $fields, array $values, ?User $user): array
    {
        $editableIds = $fields->filter(fn (CustomField $field) => $field->editableBy($user))->pluck('id');

        return collect($values)->only($editableIds)->all();
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

    /**
     * Matches Redmine's CustomField#editable? gate: a non-editable field
     * can still be seen (subject to visibleToRoles()) but not changed by
     * anyone other than an admin, who always bypasses it.
     */
    public function editableBy(?User $user): bool
    {
        return $this->editable || $user?->is_admin;
    }
}
