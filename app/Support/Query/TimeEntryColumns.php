<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Collection;
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
 * (CustomFieldVisibility, as the issue list does since A1-37).
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
