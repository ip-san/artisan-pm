<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Validation\Rule;

/**
 * Redmine's "version" field format: the value is a version, chosen from those
 * the record's project can use — its own and shared ones — optionally only
 * with a status listed in `format_options.version_status`. Stored as the
 * version's id.
 */
final class VersionFormat implements ProjectScopedFormat
{
    /** @var array<int, string|null> */
    private array $names = [];

    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Version;
    }

    public function label(): string
    {
        return 'バージョン';
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
            $this->names[$id] = Version::query()->find($id)?->name;
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
        $statuses = (array) ($field->format_options['version_status'] ?? []);

        $versions = $project !== null
            ? $project->sharedVersions()
            : Version::query()->with('project')->get();

        return $versions
            ->when($statuses !== [], fn ($all) => $all->filter(fn (Version $version) => in_array($version->status->value, $statuses, true)))
            ->sortBy(fn (Version $version) => mb_strtolower($version->name))
            ->mapWithKeys(fn (Version $version) => [(string) $version->id => $project !== null && $version->project_id !== $project->id ? "{$version->project->name} - {$version->name}" : $version->name])
            ->all();
    }
}
