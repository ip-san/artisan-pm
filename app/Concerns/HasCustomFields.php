<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Gives a model dynamic, admin-configurable custom fields backed by the
 * generic (typed) EAV table custom_field_values, keyed by this model's
 * CustomizableType discriminator. Only Issue uses this so far — Project/
 * User custom fields are future-phase scope — but the mechanism itself
 * is deliberately generic so adding a second consumer is a small diff.
 */
trait HasCustomFields
{
    /**
     * @return MorphMany<CustomFieldValue, $this>
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'customized', 'customized_type', 'customized_id');
    }

    abstract public static function customizableType(): CustomizableType;

    /**
     * The CustomField definitions that apply to this specific instance
     * (e.g. scoped by tracker/project) — implemented per consuming model
     * since what "relevant" means differs by model.
     *
     * @return Collection<int, CustomField>
     */
    abstract public function relevantCustomFields(): Collection;

    public function customFieldValueFor(CustomField $field): ?CustomFieldValue
    {
        return $this->customFieldValues->firstWhere('custom_field_id', $field->id);
    }

    public function customValue(CustomField $field): mixed
    {
        return $this->customFieldValueFor($field)?->value();
    }

    /**
     * @param  array<int, mixed>  $values  custom_field_id => raw input
     */
    public function setCustomFieldValues(array $values): void
    {
        foreach ($this->relevantCustomFields() as $field) {
            if (! array_key_exists($field->id, $values)) {
                continue;
            }

            $this->setCustomFieldValue($field, $values[$field->id]);
        }

        $this->unsetRelation('customFieldValues');
    }

    private function setCustomFieldValue(CustomField $field, mixed $raw): void
    {
        if ($field->multiple) {
            $this->setMultipleCustomFieldValues($field, (array) $raw);

            return;
        }

        $this->setSingleCustomFieldValue($field, $raw);
    }

    private function setSingleCustomFieldValue(CustomField $field, mixed $raw): void
    {
        $value = $this->customFieldValues()->firstOrNew(['custom_field_id' => $field->id]);
        $value->{$field->format()->storageColumn()} = $field->format()->prepareValue($raw);
        $value->save();
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function setMultipleCustomFieldValues(CustomField $field, array $items): void
    {
        $this->customFieldValues()->where('custom_field_id', $field->id)->delete();

        $column = $field->format()->storageColumn();

        foreach ($items as $item) {
            $prepared = $field->format()->prepareValue($item);

            if ($prepared === null) {
                continue;
            }

            $value = $this->customFieldValues()->make();
            $value->custom_field_id = $field->id;
            $value->{$column} = $prepared;
            $value->save();
        }
    }
}
