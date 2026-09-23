<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomizableType;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The filterable/sortable fields and columns of the project list —
 * Redmine's ProjectQuery. The list spans every project the viewer can see,
 * so there is no single project to resolve custom-field roles against: a
 * project custom field is offered only to administrators or when it has no
 * role restriction (the same rule the cross-project time entry list uses),
 * so a filter or column can never reveal a value the viewer may not see.
 */
final class ProjectFilterFieldRegistry
{
    /**
     * @param  Collection<int, int>  $visibleProjectIds  the parent filter only offers projects the viewer can see
     * @return Collection<string, FilterableField>
     */
    public static function forViewer(?User $viewer, Collection $visibleProjectIds): Collection
    {
        $choice = [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
        $text = [FilterOperator::Contains, FilterOperator::NotContains, FilterOperator::Equals, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
        $date = [FilterOperator::Equals, FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between, FilterOperator::InTheLastDays];

        /** @var array<int, FilterableField> $nativeFields */
        $nativeFields = [
            new NativeColumnFilter('status', __('ステータス'), 'status', FilterFieldType::Select, $choice, fn () => self::statusOptions($viewer)),
            new NativeColumnFilter('name', __('名前'), 'name', FilterFieldType::Text, $text),
            new NativeColumnFilter('identifier', __('識別子'), 'identifier', FilterFieldType::Text, $text),
            new NativeColumnFilter('description', __('説明'), 'description', FilterFieldType::Text, $text),
            new NativeColumnFilter('parent_id', __('親プロジェクト'), 'parent_id', FilterFieldType::Select, [...$choice, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], fn () => Project::query()
                ->whereIn('id', $visibleProjectIds)
                ->defaultOrder()
                ->pluck('name', 'id')
                ->all()),
            new NativeColumnFilter('is_public', __('公開'), 'is_public', FilterFieldType::Select, [FilterOperator::Equals], fn () => ['1' => __('はい'), '0' => __('いいえ')]),
            new NativeColumnFilter('created_at', __('作成日'), 'created_at', FilterFieldType::Date, $date),
            new NativeColumnFilter('updated_at', __('更新日'), 'updated_at', FilterFieldType::Date, $date),
        ];

        $customFields = self::customFields($viewer)
            ->filter(fn (CustomField $field) => $field->is_filter)
            ->map(fn (CustomField $field): FilterableField => new CustomFieldFilter($field));

        return collect($nativeFields)->concat($customFields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * Every column the list can show, native ones first, then a cf_{id}
     * column per project custom field the viewer may see.
     *
     * @return array<string, string> column key => heading
     */
    public static function columns(?User $viewer): array
    {
        return [
            ...self::nativeColumns(),
            ...self::customFields($viewer)
                ->mapWithKeys(fn (CustomField $field) => ["cf_{$field->id}" => $field->name])
                ->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function nativeColumns(): array
    {
        return [
            'name' => __('名前'),
            'identifier' => __('識別子'),
            'description' => __('説明'),
            'status' => __('ステータス'),
            'homepage' => __('ホームページ'),
            'parent_id' => __('親プロジェクト'),
            'is_public' => __('公開'),
            'created_at' => __('作成日'),
            'updated_at' => __('更新日'),
        ];
    }

    /**
     * Redmine's project_list_defaults column_names default.
     *
     * @return array<int, string>
     */
    public static function defaultColumns(): array
    {
        return ['name', 'identifier', 'description'];
    }

    /**
     * Project custom fields visible to the viewer on every project: all of
     * them for an administrator, otherwise those without a role restriction.
     *
     * @return Collection<int, CustomField>
     */
    public static function customFields(?User $viewer): Collection
    {
        return CustomField::query()
            ->where('customized_type', CustomizableType::Project)
            ->with('roles')
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $viewer?->is_admin || $field->roles->isEmpty())
            ->values();
    }

    /**
     * Archived projects are only ever listed for administrators, so only
     * they are offered that status (Redmine's project_statuses_values).
     *
     * @return array<string, string>
     */
    private static function statusOptions(?User $viewer): array
    {
        $options = [
            ProjectStatus::Active->value => __('アクティブ'),
            ProjectStatus::Closed->value => __('クローズ'),
        ];

        if ($viewer?->is_admin) {
            $options[ProjectStatus::Archived->value] = __('アーカイブ済み');
        }

        return $options;
    }
}
