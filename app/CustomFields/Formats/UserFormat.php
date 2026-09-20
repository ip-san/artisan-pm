<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Enums\UserStatus;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Redmine's "user" field format: the value is a user, chosen from the members
 * of the record's project (optionally only those holding one of the roles in
 * `format_options.user_role`). Stored as the user's id.
 */
final class UserFormat implements ProjectScopedFormat
{
    /** @var array<int, string|null> */
    private array $names = [];

    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::User;
    }

    public function label(): string
    {
        return 'ユーザー';
    }

    public function storageColumn(): string
    {
        return 'value_int';
    }

    public function prepareValue(mixed $input): mixed
    {
        return $input === '' || $input === null ? null : (int) $input;
    }

    public function castValue(mixed $stored, CustomField $field): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $id = (int) $stored;

        if (! array_key_exists($id, $this->names)) {
            $this->names[$id] = User::query()->find($id)?->displayName();
        }

        return $this->names[$id];
    }

    public function validationRules(CustomField $field): array
    {
        return $this->rulesFor($field, null);
    }

    public function rulesFor(CustomField $field, ?Project $project): array
    {
        return ['integer', Rule::in(array_map('intval', array_keys($this->optionsFor($field, $project))))];
    }

    public function options(CustomField $field): array
    {
        return $this->optionsFor($field, null);
    }

    public function optionsFor(CustomField $field, ?Project $project): array
    {
        $query = User::query()->where('status', UserStatus::Active)->orderBy('name');

        if ($project !== null) {
            $query->whereIn('id', $this->memberIds($field, $project));
        }

        return $query->get()->mapWithKeys(fn (User $user) => [(string) $user->id => $user->displayName()])->all();
    }

    /**
     * @return array<int, int>
     */
    private function memberIds(CustomField $field, Project $project): array
    {
        $roleIds = array_map('intval', (array) ($field->format_options['user_role'] ?? []));

        if ($roleIds === []) {
            return $project->memberUserIds()->all();
        }

        $members = $project->members()->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $roleIds))->get();
        $groupIds = $members->pluck('group_id')->filter();

        return $members->pluck('user_id')->filter()
            ->merge($groupIds->isEmpty() ? [] : User::query()->whereHas('groups', fn ($groups) => $groups->whereIn('groups.id', $groupIds))->pluck('id'))
            ->unique()->values()->all();
    }
}
