<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;
use App\Support\Format\Hours;

/**
 * The extra columns shown for subtasks and related issues on an issue's
 * page — Redmine's related_issues_default_columns setting (which lists
 * every inline query column except the always-shown tracker and subject)
 * together with display_related_issues_table_headers.
 */
final class RelatedIssueColumns
{
    /**
     * Selectable columns, in display order, with their labels. Tracker and
     * subject are excluded because the subject cell always shows both, as
     * Redmine removes them from this setting's picker.
     *
     * @var array<string, string>
     */
    public const array AVAILABLE = [
        'status_id' => 'ステータス',
        'priority_id' => '優先度',
        'category_id' => 'カテゴリ',
        'assigned_to_id' => '担当者',
        'author_id' => '作成者',
        'fixed_version_id' => '対象バージョン',
        'start_date' => '開始日',
        'due_date' => '期日',
        'created_at' => '作成日',
        'updated_at' => '更新日',
        'closed_on' => '終了日',
        'estimated_hours' => '予定工数',
        'done_ratio' => '進捗率',
        'project_id' => 'プロジェクト',
    ];

    /**
     * AVAILABLE with its labels translated, for display.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'status_id' => __('ステータス'),
            'priority_id' => __('優先度'),
            'category_id' => __('カテゴリ'),
            'assigned_to_id' => __('担当者'),
            'author_id' => __('作成者'),
            'fixed_version_id' => __('対象バージョン'),
            'start_date' => __('開始日'),
            'due_date' => __('期日'),
            'created_at' => __('作成日'),
            'updated_at' => __('更新日'),
            'closed_on' => __('終了日'),
            'estimated_hours' => __('予定工数'),
            'done_ratio' => __('進捗率'),
            'project_id' => __('プロジェクト'),
        ];
    }

    /**
     * Every selectable column with its label: labels() then one `cf_<id>`
     * per issue custom field that fits in a table cell (Redmine offers the
     * query's inline columns, which leave out long text).
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        $customFields = CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->where('field_format', '!=', CustomFieldFormat::Text->value)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => ["cf_{$id}" => $name])
            ->all();

        return [...self::labels(), ...$customFields];
    }

    /**
     * Redmine's config/settings.yml default.
     *
     * @var array<int, string>
     */
    public const array DEFAULT = ['status_id', 'assigned_to_id', 'start_date', 'due_date', 'done_ratio'];

    /**
     * The configured columns in the configured order, ignoring anything no
     * longer selectable (a deleted custom field).
     *
     * @return array<string, string> key => label
     */
    public static function selected(): array
    {
        $configured = Setting::get('related_issues_default_columns', self::DEFAULT);
        $available = self::available();

        return collect(is_array($configured) ? $configured : self::DEFAULT)
            ->filter(fn ($key) => is_string($key) && array_key_exists($key, $available))
            ->unique()
            ->mapWithKeys(fn (string $key) => [$key => $available[$key]])
            ->all();
    }

    public static function showHeaders(): bool
    {
        return (bool) Setting::get('display_related_issues_table_headers', false);
    }

    /**
     * The Issue relations a column needs loaded, so a table of related
     * issues never lazy-loads per row.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function relationsFor(array $keys): array
    {
        $map = [
            'status_id' => ['status'],
            'priority_id' => ['priority'],
            'category_id' => ['category'],
            'assigned_to_id' => ['assignedTo', 'assignedToGroup'],
            'author_id' => ['author'],
            'fixed_version_id' => ['fixedVersion'],
            'project_id' => ['project'],
        ];
        $relations = array_merge([], ...array_values(array_intersect_key($map, array_flip($keys))));

        if (collect($keys)->contains(fn (string $key) => str_starts_with($key, 'cf_'))) {
            $relations[] = 'customFieldValues.customField';
        }

        return $relations;
    }

    /**
     * Per issue, the ids of the custom fields it may show: its own fields
     * (project and tracker) that the viewer's roles may see there
     * (Issue::relevantCustomFields()), worked out once per project and
     * tracker.
     *
     * @param  iterable<int, Issue>  $issues
     * @return array<int, array<int, int>> issue id => custom field ids
     */
    public static function visibleCustomFieldIds(iterable $issues, ?User $viewer): array
    {
        $byKind = [];
        $visible = [];

        foreach ($issues as $issue) {
            $kind = "{$issue->project_id}-{$issue->tracker_id}";
            $byKind[$kind] ??= $issue->relevantCustomFields($viewer)->pluck('id')->all();
            $visible[$issue->id] = $byKind[$kind];
        }

        return $visible;
    }

    /**
     * The text of one column for an issue; relations must already be
     * loaded (see relationsFor()). A custom field column shows only a field
     * listed in $visibleCustomFieldIds (see visibleCustomFieldIds()).
     *
     * @param  array<int, int>  $visibleCustomFieldIds
     */
    public static function value(Issue $issue, string $key, array $visibleCustomFieldIds = []): string
    {
        if (str_starts_with($key, 'cf_')) {
            $fieldId = (int) substr($key, 3);

            if (! in_array($fieldId, $visibleCustomFieldIds, true)) {
                return '';
            }

            return $issue->customFieldValues
                ->where('custom_field_id', $fieldId)
                ->map(fn (CustomFieldValue $value) => (string) $value->displayValue())
                ->filter(fn (string $text) => $text !== '')
                ->join(', ');
        }

        return match ($key) {
            'status_id' => $issue->status->name,
            'priority_id' => $issue->priority->name,
            'category_id' => $issue->category?->name ?? '',
            'assigned_to_id' => $issue->assigneeName() ?? '',
            'author_id' => $issue->author->displayName(),
            'fixed_version_id' => $issue->fixedVersion?->name ?? '',
            'start_date' => DateTimes::date($issue->start_date) ?? '',
            'due_date' => DateTimes::date($issue->due_date) ?? '',
            'created_at' => DateTimes::dateOf($issue->created_at) ?? '',
            'updated_at' => DateTimes::dateOf($issue->updated_at) ?? '',
            'closed_on' => DateTimes::dateOf($issue->closed_on) ?? '',
            'estimated_hours' => $issue->estimated_hours !== null ? Hours::format((float) $issue->estimated_hours) : '',
            'done_ratio' => "{$issue->done_ratio}%",
            'project_id' => $issue->project->name,
            default => '',
        };
    }
}
