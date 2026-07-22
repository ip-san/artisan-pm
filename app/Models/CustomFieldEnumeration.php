<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomFieldEnumerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * One managed option of an "enumeration"-format custom field — matches
 * Redmine's CustomFieldEnumeration. Distinct from the App\Models\Enumeration
 * model (which represents the built-in IssuePriority/TimeEntryActivity/
 * DocumentCategory admin lists); this one belongs to a single
 * user-defined CustomField instead.
 */
#[Fillable(['custom_field_id', 'name', 'position', 'active'])]
final class CustomFieldEnumeration extends Model implements Sortable
{
    /** @use HasFactory<CustomFieldEnumerationFactory> */
    use HasFactory, SortableTrait;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    /** @var array{order_column_name: string, sort_when_creating: bool} */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return Builder<CustomFieldEnumeration>
     */
    public function buildSortQuery(): Builder
    {
        return self::query()->where('custom_field_id', $this->custom_field_id);
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * Deletes this option, first moving existing custom field values that
     * reference it to $reassignToId (or clearing them when null) — matches
     * Redmine's CustomFieldEnumeration#destroy(reassign_to), except values
     * are always cleared rather than left pointing at a nonexistent id.
     * The storage column comes from the owning field's format, the one
     * place that knows where enumeration ids live.
     */
    public function deleteAndReassign(?int $reassignToId): void
    {
        $column = $this->customField->format()->storageColumn();

        CustomFieldValue::query()
            ->where('custom_field_id', $this->custom_field_id)
            ->where($column, (string) $this->id)
            ->update([$column => $reassignToId !== null ? (string) $reassignToId : null]);

        $this->delete();
    }
}
