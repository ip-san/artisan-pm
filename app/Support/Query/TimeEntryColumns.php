<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The time entry list columns beyond the entry's own attributes, after
 * Redmine's TimeEntryQuery#available_columns: the entry's custom fields,
 * the issue's tracker/parent/status/category/target version, and the
 * issue's and the project's custom fields (QueryAssociationColumn /
 * QueryAssociationCustomFieldColumn).
 *
 * A column is offered when its field has no role restriction (or the
 * viewer is an administrator), or the viewer may see it in one of the
 * listed projects; each row then shows a value only where the viewer may
 * see it: an issue column only for an issue the viewer can see, a custom
 * field only in a project where its roles let the viewer see it
 * (CustomFieldVisibility, as the issue list does since A1-37). Sorting and
 * grouping by these columns follow the same rule: a value the viewer may
 * not see sorts and groups as blank.
 */
final class TimeEntryColumns
{
    private readonly CustomFieldVisibility $visibility;

    /** @var ?Collection<int, CustomField> */
    private ?Collection $fields = null;

    /** @var array<int, bool> issue id => whether the viewer may see it */
    private array $issueVisible = [];

    /**
     * @param  Collection<int, Project>  $projects  the projects the list covers
     */
    public function __construct(
        private readonly ?User $viewer,
        private readonly Collection $projects,
    ) {
        $this->visibility = CustomFieldVisibility::for($viewer);
    }

    /**
     * @return array<string, string> column key => heading
     */
    public function labels(): array
    {
        $labels = [
            'issue_tracker' => __('課題のトラッカー'),
            'issue_parent' => __('課題の親課題'),
            'issue_status' => __('課題のステータス'),
            'issue_category' => __('課題のカテゴリ'),
            'issue_fixed_version' => __('課題の対象バージョン'),
        ];

        foreach ($this->fields() as $field) {
            [$key, $label] = match ($field->customized_type) {
                CustomizableType::TimeEntry => ["cf_{$field->id}", $field->name],
                CustomizableType::Issue => ["issue_cf_{$field->id}", __('課題の:name', ['name' => $field->name])],
                default => ["project_cf_{$field->id}", __('プロジェクトの:name', ['name' => $field->name])],
            };
            $labels[$key] = $label;
        }

        return $labels;
    }

    /**
     * The columns the list can be grouped by (Redmine's groupable
     * association and custom field columns): the issue's tracker, status,
     * category and target version, and single-value custom fields other
     * than long text and files.
     *
     * @return array<string, string> column key => heading
     */
    public function groupableLabels(): array
    {
        $groupable = ['issue_tracker', 'issue_status', 'issue_category', 'issue_fixed_version'];

        foreach ($this->fields() as $field) {
            if (! $field->multiple && ! in_array($field->field_format, [CustomFieldFormat::Text, CustomFieldFormat::Attachment], true)) {
                $groupable[] = $this->keyFor($field);
            }
        }

        return array_intersect_key($this->labels(), array_flip($groupable));
    }

    /**
     * Orders $query by one of these columns, through a correlated subquery
     * that sees only issues the viewer may see and custom field values in
     * projects where the viewer may see the field. Blank sorts first
     * ascending, last descending, as the custom field columns of the issue
     * list do. Returns false for a key it does not sort (a multi-value
     * field, a field not offered).
     *
     * @param  Builder<TimeEntry>  $query
     */
    public function applySort(Builder $query, string $key, string $direction): bool
    {
        $value = $this->sortValue($key);

        if ($value === null) {
            return false;
        }

        $order = $direction === 'desc' ? 'DESC' : 'ASC';
        $sql = '('.$value->toSql().')';

        $query->orderByRaw("{$sql} IS NOT NULL {$order}, {$sql} {$order}", [...$value->getBindings(), ...$value->getBindings()]);

        return true;
    }

    private function sortValue(string $key): ?QueryBuilder
    {
        $visibleIssueIds = Issue::query()->select('issues.id')->visible($this->viewer)->toBase();
        $issue = fn () => DB::table('issues as sort_issue')
            ->whereColumn('sort_issue.id', 'time_entries.issue_id')
            ->whereIn('sort_issue.id', $visibleIssueIds);

        if (preg_match('/^(cf|issue_cf|project_cf)_(\d+)$/', $key, $match) === 1) {
            $field = $this->fields()->firstWhere('id', (int) $match[2]);

            if ($field === null || $field->multiple || $this->keyFor($field) !== $key) {
                return null;
            }

            $visibleProjectIds = $this->visibility->visibleProjectIds($field, $this->projects);
            $values = DB::table('custom_field_values as sort_cfv')
                ->where('sort_cfv.customized_type', $field->customized_type->value)
                ->where('sort_cfv.custom_field_id', $field->id)
                ->orderByDesc('sort_cfv.id')
                ->limit(1)
                ->select('sort_cfv.'.$field->format()->storageColumn());

            match ($match[1]) {
                'cf' => $values->whereColumn('sort_cfv.customized_id', 'time_entries.id')
                    ->when($visibleProjectIds !== null, fn (QueryBuilder $q) => $q->whereIn('time_entries.project_id', $visibleProjectIds)),
                'issue_cf' => $values->join('issues as sort_issue', 'sort_issue.id', '=', 'sort_cfv.customized_id')
                    ->whereColumn('sort_issue.id', 'time_entries.issue_id')
                    ->whereIn('sort_issue.id', $visibleIssueIds)
                    ->when($visibleProjectIds !== null, fn (QueryBuilder $q) => $q->whereIn('sort_issue.project_id', $visibleProjectIds)),
                default => $values->whereColumn('sort_cfv.customized_id', 'time_entries.project_id')
                    ->when($visibleProjectIds !== null, fn (QueryBuilder $q) => $q->whereIn('time_entries.project_id', $visibleProjectIds)),
            };

            return $values;
        }

        return match ($key) {
            'issue_tracker' => $issue()->join('trackers as sort_tracker', 'sort_tracker.id', '=', 'sort_issue.tracker_id')->select('sort_tracker.position'),
            'issue_status' => $issue()->join('issue_statuses as sort_status', 'sort_status.id', '=', 'sort_issue.status_id')->select('sort_status.position'),
            'issue_category' => $issue()->join('issue_categories as sort_category', 'sort_category.id', '=', 'sort_issue.category_id')->select('sort_category.name'),
            'issue_fixed_version' => $issue()->join('versions as sort_version', 'sort_version.id', '=', 'sort_issue.fixed_version_id')->select('sort_version.name'),
            'issue_parent' => $issue()->whereIn('sort_issue.parent_id', $visibleIssueIds)->select('sort_issue.parent_id'),
            default => null,
        };
    }

    private function keyFor(CustomField $field): string
    {
        return match ($field->customized_type) {
            CustomizableType::TimeEntry => "cf_{$field->id}",
            CustomizableType::Issue => "issue_cf_{$field->id}",
            default => "project_cf_{$field->id}",
        };
    }

    /**
     * The relations the given columns read, for eager loading.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    public static function relations(array $columns): array
    {
        $relations = [];

        foreach ($columns as $column) {
            $relations = [...$relations, ...match (true) {
                $column === 'issue_tracker' => ['issue.tracker'],
                $column === 'issue_parent' => ['issue.parent'],
                $column === 'issue_status' => ['issue.status'],
                $column === 'issue_category' => ['issue.category'],
                $column === 'issue_fixed_version' => ['issue.fixedVersion'],
                str_starts_with($column, 'issue_cf_') => ['issue.project', 'issue.customFieldValues.customField'],
                str_starts_with($column, 'project_cf_') => ['project.customFieldValues.customField'],
                default => [],
            }];
        }

        return array_values(array_unique($relations));
    }

    /**
     * Whether $key is one of these columns (a custom field column whose
     * field is not offered counts too, so it shows nothing).
     */
    public static function handles(string $key): bool
    {
        return str_starts_with($key, 'issue_') && $key !== 'issue_id'
            || str_starts_with($key, 'cf_')
            || str_starts_with($key, 'project_cf_');
    }

    public function value(TimeEntry $entry, string $key): string
    {
        $issue = $entry->issue;

        if (preg_match('/^(cf|issue_cf|project_cf)_(\d+)$/', $key, $match) === 1) {
            $field = $this->fields()->firstWhere('id', (int) $match[2]);

            return match (true) {
                $field === null => '',
                $match[1] === 'cf' => $this->customValue($field, $entry->project, $entry->customFieldValues),
                $match[1] === 'issue_cf' => $issue !== null && $this->issueIsVisible($issue) ? $this->customValue($field, $issue->project, $issue->customFieldValues) : '',
                default => $this->customValue($field, $entry->project, $entry->project->customFieldValues),
            };
        }

        if ($issue === null || ! $this->issueIsVisible($issue)) {
            return '';
        }

        return match ($key) {
            'issue_tracker' => $issue->tracker->name,
            'issue_parent' => $issue->parent !== null && $this->issueIsVisible($issue->parent) ? "#{$issue->parent->id} {$issue->parent->subject}" : '',
            'issue_status' => $issue->status->name,
            'issue_category' => $issue->category->name ?? '',
            'issue_fixed_version' => $issue->fixedVersion->name ?? '',
            default => '',
        };
    }

    /**
     * The custom fields behind the columns: time entry, issue and project
     * fields the viewer may see in one of the listed projects.
     *
     * @return Collection<int, CustomField>
     */
    private function fields(): Collection
    {
        return $this->fields ??= CustomField::query()
            ->whereIn('customized_type', [CustomizableType::TimeEntry, CustomizableType::Issue, CustomizableType::Project])
            ->with(['projects', 'roles'])
            ->orderBy('position')
            ->get()
            // A field with no role restriction (or any field for an
            // administrator) is offered as before, whatever the projects.
            ->filter(fn (CustomField $field) => $this->visibility->visibleProjectIds($field, collect()) === null
                || $this->projects->contains(fn (Project $project) => $field->appliesToProject($project) && $this->visibility->isVisibleIn($field, $project)))
            ->sortBy(fn (CustomField $field) => [match ($field->customized_type) {
                CustomizableType::TimeEntry => 0,
                CustomizableType::Issue => 1,
                default => 2,
            }, $field->position])
            ->values();
    }

    /**
     * @param  Collection<int, CustomFieldValue>  $values
     */
    private function customValue(CustomField $field, Project $project, Collection $values): string
    {
        if (! $this->visibility->isVisibleIn($field, $project)) {
            return '';
        }

        return $values
            ->where('custom_field_id', $field->id)
            ->map(fn (CustomFieldValue $value) => (string) $value->displayValue())
            ->join(', ');
    }

    private function issueIsVisible(Issue $issue): bool
    {
        return $this->issueVisible[$issue->id] ??= Gate::forUser($this->viewer)->allows('view', $issue);
    }
}
