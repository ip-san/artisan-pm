<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'custom_field_id', 'customized_type', 'customized_id',
    'value_string', 'value_text', 'value_int', 'value_float', 'value_date', 'value_bool',
])]
final class CustomFieldValue extends Model
{
    protected function casts(): array
    {
        return [
            'value_date' => 'date',
            'value_bool' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function customized(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'customized_type', 'customized_id');
    }

    /**
     * The raw value read through this row's owning CustomField's format.
     */
    public function value(): mixed
    {
        $column = $this->customField->format()->storageColumn();

        return $this->customField->format()->castValue($this->{$column});
    }
}
