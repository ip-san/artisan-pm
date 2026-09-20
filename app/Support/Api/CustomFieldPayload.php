<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Custom field values in the REST API, in Redmine's shape:
 * `custom_fields: [{id, name, value}]` (`value` an array for a multi-value
 * field) on the way out and `custom_fields: [{id, value}]` on the way in.
 * Values are the stored ones — an enumeration field carries the option's id,
 * not its name — so a read can be sent back unchanged. Which fields appear is
 * decided by the model's own relevantCustomFields() (role visibility included);
 * on writes, a field the caller may not edit is ignored and an unknown id is
 * skipped, as in Redmine.
 */
final class CustomFieldPayload
{
    /**
     * The model's visible custom fields with their current values.
     *
     * @return array<int, array{id: int, name: string, multiple?: true, value: mixed}>
     */
    public static function read(Model $model): array
    {
        $model->loadMissing('customFieldValues');

        return self::relevantFields($model)->map(function (CustomField $field) use ($model): array {
            $column = $field->format()->storageColumn();
            $rows = $model->customFieldValues->where('custom_field_id', $field->id);

            $stored = fn (CustomFieldValue $row): ?string => self::asString($row->{$column});

            return [
                'id' => $field->id,
                'name' => $field->name,
                ...($field->multiple ? ['multiple' => true] : []),
                'value' => $field->multiple ? $rows->map($stored)->filter()->values()->all() : ($rows->isEmpty() ? null : $stored($rows->first())),
            ];
        })->values()->all();
    }

    /**
     * The model's visible custom fields, worked out once per project and
     * tracker in a request: a list of issues in one project asks the same
     * question for every row.
     *
     * @return Collection<int, CustomField>
     */
    private static function relevantFields(Model $model): Collection
    {
        $projectId = $model->getAttribute('project_id');
        $trackerId = $model->getAttribute('tracker_id');
        $key = 'custom_field_payload:'.$model::class.':'.($projectId ?? '').':'.($trackerId ?? '');
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            // A model that belongs to a project answers from just the ids on
            // a throwaway copy, so a list does not load the project (and the
            // fields) once per row.
            $subject = $model;

            if ($projectId !== null) {
                $projectKey = 'custom_field_payload:project:'.$projectId;

                if (! $attributes->has($projectKey)) {
                    $attributes->set($projectKey, Project::query()->find($projectId));
                }

                $subject = $model->newInstance()->forceFill(['project_id' => $projectId, 'tracker_id' => $trackerId])->setRelation('project', $attributes->get($projectKey));
            }

            $attributes->set($key, $subject->relevantCustomFields());
        }

        return $attributes->get($key);
    }

    /**
     * The values in the request's `custom_fields`, checked against $fields:
     * id => value for the fields the caller may edit. Nothing when the key is
     * absent. With $requireAll (creating) every field is validated, so a
     * required one left out is an error; otherwise only the ones sent are.
     *
     * @param  Collection<int, CustomField>  $fields
     * @return array<int, mixed>
     *
     * @throws ValidationException
     */
    public static function extract(Request $request, Collection $fields, ?User $user, bool $requireAll = false, ?Project $project = null): array
    {
        $items = $request->input('custom_fields');

        if (! is_array($items)) {
            if ($requireAll) {
                $items = [];
            } else {
                return [];
            }
        }

        $byId = $fields->keyBy('id');
        $values = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id']) || ! $byId->has((int) $item['id'])) {
                continue;
            }

            $field = $byId->get((int) $item['id']);

            if (! $field->editableBy($user)) {
                continue;
            }

            $values[$field->id] = $field->multiple ? (array) ($item['value'] ?? []) : ($item['value'] ?? null);
        }

        $checked = $requireAll ? $fields->filter(fn (CustomField $field) => $field->editableBy($user)) : $fields->whereIn('id', array_keys($values));

        $validator = Validator::make(['custom_fields' => $values], collect(CustomField::formValidationRules($checked, null, $project))
            ->mapWithKeys(fn ($rules, $key) => [str_replace('customFieldValues.', 'custom_fields.', $key) => $rules])
            ->all());

        $validator->validate();

        return $values;
    }

    private static function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
