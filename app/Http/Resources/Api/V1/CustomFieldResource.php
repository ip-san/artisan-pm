<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * tracker_ids/role_ids as flat id arrays, matching this app's own resource
 * convention (GroupResource/MembershipResource) rather than Redmine's
 * nested {id, name} tracker/role objects. possible_values keeps Redmine's
 * own {value, label} shape since CustomField::format()->options() already
 * returns that same value=>label mapping and there's no simpler flat
 * equivalent for it. description/is_for_all/is_filter/visible/
 * default_value_mode are omitted — this app has no such columns at all,
 * not just unexposed here.
 *
 * @property CustomField $resource
 */
final class CustomFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $field = $this->resource;

        return [
            'id' => $field->id,
            'name' => $field->name,
            'customized_type' => $field->customized_type->value,
            'field_format' => $field->field_format->value,
            'regexp' => $field->regexp,
            'min_length' => $field->min_length,
            'max_length' => $field->max_length,
            'is_required' => $field->is_required,
            'searchable' => $field->searchable,
            'multiple' => $field->multiple,
            'editable' => $field->editable,
            'default_value' => $field->default_value,
            'possible_values' => collect($field->format()->options($field))
                ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])
                ->values()
                ->all(),
            'tracker_ids' => $field->trackers->pluck('id')->all(),
            'role_ids' => $field->roles->pluck('id')->all(),
        ];
    }
}
