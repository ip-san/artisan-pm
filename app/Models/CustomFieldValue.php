<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldFormat;
use App\Support\Attachments\AttachmentFieldFile;
use App\Support\Format\DateTimes;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        $this->loadMissing('customField');

        $column = $this->customField->format()->storageColumn();

        return $this->customField->format()->castValue($this->{$column}, $this->customField);
    }

    /**
     * The value as a screen, CSV or PDF shows it: a date field's value in the
     * `date_format` setting (Redmine's format_object), anything else as
     * value(); an attachment field's file as an AttachmentFieldFile. Forms, the
     * API and filters keep value() (ISO dates, the media id).
     */
    public function displayValue(): mixed
    {
        $value = $this->value();

        if ($this->customField->field_format === CustomFieldFormat::Attachment) {
            $media = $value === null ? null : Media::query()->find($value);

            return $media === null ? null : new AttachmentFieldFile($media->id, $media->file_name);
        }

        if ($this->customField->field_format === CustomFieldFormat::Date && ($value instanceof DateTimeInterface || (is_string($value) && $value !== ''))) {
            return DateTimes::date($value);
        }

        return $value;
    }
}
