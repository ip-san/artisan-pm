<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * The row axes offered on a time report and the lookup from the keys a page
 * keeps in its URL: the native criteria, `project` on the cross-project
 * report, and — Redmine offers the same — custom fields of time entries
 * (`cf_time_{id}`) and of issues (`cf_issue_{id}`) whose format groups
 * (list, boolean, enumeration and numeric/date fields, single-valued).
 */
final class TimeReportAxes
{
    private const array GROUPABLE_FORMATS = [
        CustomFieldFormat::List, CustomFieldFormat::Bool, CustomFieldFormat::Enumeration,
        CustomFieldFormat::Int, CustomFieldFormat::Float, CustomFieldFormat::Date,
    ];

    /**
     * Every axis the viewer may pick, keyed by axis key.
     *
     * @param  Collection<int, Project>|null  $projects  the projects in scope, to judge which custom fields are visible
     * @param  bool  $includeProject  true on the cross-project report, which adds the `project` axis
     * @return array<string, TimeReportAxis>
     */
    public static function available(?User $viewer, ?Collection $projects = null, bool $includeProject = false): array
    {
        $axes = [];

        foreach (TimeReportCriterion::cases() as $criterion) {
            $axes[$criterion->value] = $criterion->axis();
        }

        if ($includeProject && $projects !== null) {
            $axes = ['project' => self::projectAxis($projects), ...$axes];
        }

        foreach (self::customFields($viewer, $projects) as $field) {
            $axis = self::customFieldAxis($field);
            $axes[$axis->key] = $axis;
        }

        return $axes;
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, TimeReportAxis>  $available
     * @return array<int, TimeReportAxis>
     */
    public static function resolve(array $keys, array $available): array
    {
        return collect($keys)->unique()->map(fn (string $key) => $available[$key] ?? null)->filter()->take(3)->values()->all();
    }

    /**
     * @param  Collection<int, Project>|null  $projects
     * @return Collection<int, CustomField>
     */
    private static function customFields(?User $viewer, ?Collection $projects): Collection
    {
        $fields = CustomField::query()
            ->whereIn('customized_type', [CustomizableType::TimeEntry, CustomizableType::Issue])
            ->where('multiple', false)
            ->with(['projects', 'roles'])
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => in_array($field->field_format, self::GROUPABLE_FORMATS, true));

        // A field is offered when some project in scope shows it to the viewer.
        return $fields->filter(function (CustomField $field) use ($viewer, $projects) {
            foreach ($projects ?? collect() as $project) {
                if ($field->appliesToProject($project) && ($viewer?->is_admin || $field->visibleToRoles(app(\App\Support\Authorization\AuthorizationService::class)->rolesFor($viewer, $project)))) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private static function projectAxis(Collection $projects): TimeReportAxis
    {
        return new TimeReportAxis(
            key: 'project',
            label: 'プロジェクト',
            expression: 'time_entries.project_id',
            needsIssueJoin: false,
            join: null,
            labels: fn (Collection $ids) => $projects->whereIn('id', $ids)->pluck('name', 'id')->all(),
        );
    }

    private static function customFieldAxis(CustomField $field): TimeReportAxis
    {
        $onTimeEntry = $field->customized_type === CustomizableType::TimeEntry;
        $alias = 'cfv_'.$field->id;
        $column = $field->format()->storageColumn();

        return new TimeReportAxis(
            key: ($onTimeEntry ? 'cf_time_' : 'cf_issue_').$field->id,
            label: $field->name.($onTimeEntry ? '' : '(課題)'),
            expression: "{$alias}.{$column}",
            needsIssueJoin: ! $onTimeEntry,
            join: function (Builder $query) use ($field, $alias, $onTimeEntry): void {
                $query->leftJoin("custom_field_values as {$alias}", function (JoinClause $join) use ($field, $alias, $onTimeEntry): void {
                    $join->on("{$alias}.customized_id", '=', $onTimeEntry ? 'time_entries.id' : 'issues.id')
                        ->where("{$alias}.customized_type", $onTimeEntry ? (new TimeEntry)->getMorphClass() : 'issue')
                        ->where("{$alias}.custom_field_id", $field->id);
                });
            },
            labels: fn (Collection $values) => $values->mapWithKeys(fn ($value) => [
                $value => match (true) {
                    $field->field_format === CustomFieldFormat::Bool => $value ? 'はい' : 'いいえ',
                    default => (string) $field->format()->castValue($value, $field),
                },
            ])->all(),
        );
    }
}
