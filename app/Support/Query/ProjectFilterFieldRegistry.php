<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
     * @var array<int, string>
     */
    public const array DEFAULT_COLUMNS = ['name', 'identifier', 'description'];

    /**
     * @var array<int, string>
     */
    public const array DISPLAY_TYPES = ['board', 'list'];

    /**
     * @param  Collection<int, int>  $visibleProjectIds  the parent filter only offers projects the viewer can see
     * @return Collection<string, FilterableField>
     */
    public static function forViewer(?User $viewer, Collection $visibleProjectIds): Collection
    {
        $choice = [FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::In, FilterOperator::NotIn];
        $text = [FilterOperator::Contains, FilterOperator::ContainsAny, FilterOperator::NotContains, FilterOperator::StartsWith, FilterOperator::EndsWith, FilterOperator::Equals, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty];
        $date = FilterOperator::dateChoices(withEmptiness: false);

        /** @var array<int, FilterableField> $nativeFields */
        $nativeFields = [
            new NativeColumnFilter('status', __('ステータス'), 'status', FilterFieldType::Select, $choice, fn () => self::statusOptions($viewer)),
            self::projectChoiceFilter('id', __('プロジェクト'), $choice, $viewer, $visibleProjectIds),
            new NativeColumnFilter('name', __('名前'), 'name', FilterFieldType::Text, $text),
            new NativeColumnFilter('identifier', __('識別子'), 'identifier', FilterFieldType::Text, $text),
            new NativeColumnFilter('description', __('説明'), 'description', FilterFieldType::Text, $text),
            self::projectChoiceFilter('parent_id', __('親プロジェクト'), [...$choice, FilterOperator::IsEmpty, FilterOperator::IsNotEmpty], $viewer, $visibleProjectIds),
            new NativeColumnFilter('is_public', __('公開'), 'is_public', FilterFieldType::Select, [FilterOperator::Equals], fn () => ['1' => __('はい'), '0' => __('いいえ')]),
            new NativeColumnFilter('created_at', __('作成日'), 'created_at', FilterFieldType::Date, $date, storesTime: true),
            new NativeColumnFilter('updated_at', __('更新日'), 'updated_at', FilterFieldType::Date, $date, storesTime: true),
        ];

        $customFields = self::customFields($viewer)
            ->filter(fn (CustomField $field) => $field->is_filter && $field->field_format !== CustomFieldFormat::Attachment)
            ->map(fn (CustomField $field): FilterableField => new CustomFieldFilter($field));

        return collect($nativeFields)->concat($customFields)->keyBy(fn (FilterableField $field) => $field->key());
    }

    /**
     * A filter on a project id column (Redmine's ProjectQuery "id" and
     * "parent_id"): its values are the visible projects in tree order,
     * after `mine` (<< マイプロジェクト >>) and `bookmarks`
     * (<< ブックマーク >>) for a signed-in viewer, which stand for their
     * member and bookmarked projects when the filter runs — Query#statement's
     * substitution, so they match nothing when there are none. Redmine
     * lists the two only when the user has some; they are always offered
     * here so a saved query or REST call using them keeps meaning "none"
     * instead of being dropped as an unknown value.
     *
     * @param  array<int, FilterOperator>  $operators
     * @param  Collection<int, int>  $visibleProjectIds
     */
    private static function projectChoiceFilter(string $key, string $label, array $operators, ?User $viewer, Collection $visibleProjectIds): FilterableField
    {
        return new CallbackFilter(
            $key,
            $label,
            FilterFieldType::Select,
            $operators,
            function (Builder $query, FilterOperator $operator, array $values) use ($key, $viewer): Builder {
                if (! $operator->requiresValue()) {
                    return FilterOperatorApplier::apply($query, $query->qualifyColumn($key), $operator, $values);
                }

                if ($values === []) {
                    return $query;
                }

                // One of several ids, as Redmine's list "=" / "!" — mine and
                // bookmarks may stand for many projects, or for none.
                $ids = self::substituteProjectValues($values, $viewer);
                $excludes = in_array($operator, [FilterOperator::NotEquals, FilterOperator::NotIn], true);

                return $excludes
                    ? $query->whereNotIn($query->qualifyColumn($key), $ids)
                    : $query->whereIn($query->qualifyColumn($key), $ids);
            },
            function () use ($viewer, $visibleProjectIds): array {
                $options = $viewer !== null
                    ? ['mine' => __('<< マイプロジェクト >>'), 'bookmarks' => __('<< ブックマーク >>')]
                    : [];

                return $options + Project::query()
                    ->whereIn('id', $visibleProjectIds)
                    ->defaultOrder()
                    ->pluck('name', 'id')
                    ->all();
            },
        );
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function substituteProjectValues(array $values, ?User $viewer): array
    {
        $ids = [];

        foreach ($values as $value) {
            $ids = [...$ids, ...match ((string) $value) {
                'mine' => $viewer?->memberships()->pluck('project_id')->all() ?? [],
                'bookmarks' => $viewer?->bookmarkedProjects()->pluck('projects.id')->all() ?? [],
                default => ctype_digit((string) $value) ? [(int) $value] : [],
            }];
        }

        return array_map('strval', array_values(array_unique($ids)));
    }

    /**
     * Orders a project query by one of the columns() keys; an unknown key
     * leaves it unsorted. Shared by the project list and the admin one.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function applySort(Builder $query, ?string $sortKey, string $direction, ?User $viewer): Builder
    {
        if ($sortKey === null || ! self::isSortable($sortKey, self::columns($viewer))) {
            return $query;
        }

        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if (str_starts_with($sortKey, 'cf_')) {
            $field = self::customFields($viewer)->firstWhere('id', (int) substr($sortKey, 3));

            return $field !== null ? (new CustomFieldFilter($field))->applySort($query, $direction) : $query;
        }

        // Redmine sorts the parent column by tree position (lft).
        return $query->orderBy($sortKey === 'parent_id' ? '_lft' : $sortKey, $direction);
    }

    /**
     * Every column sorts except the last activity date, which Redmine
     * computes after the query (QueryColumn without :sortable).
     *
     * @param  array<string, string>  $columns  columns() for the viewer
     */
    public static function isSortable(string $key, array $columns): bool
    {
        return $key !== 'last_activity_date' && array_key_exists($key, $columns);
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
            'last_activity_date' => __('最終活動日'),
        ];
    }

    /**
     * The columns a fresh list starts with: the setting
     * `project_list_defaults` (stored like Redmine's, as
     * `{column_names: [...]}`), limited to native columns, or Redmine's
     * default of name, identifier and description.
     *
     * @return array<int, string>
     */
    public static function defaultColumns(): array
    {
        $stored = Setting::get('project_list_defaults', []);
        $configured = is_array($stored) ? ($stored['column_names'] ?? null) : null;
        $valid = is_array($configured)
            ? array_values(array_unique(array_filter($configured, fn ($key) => is_string($key) && array_key_exists($key, self::nativeColumns()))))
            : [];

        return $valid !== [] ? $valid : self::DEFAULT_COLUMNS;
    }

    /**
     * Redmine's `project_list_display_type`: `board` (cards, its default)
     * or `list` (a table).
     */
    public static function defaultDisplayType(): string
    {
        $configured = Setting::get('project_list_display_type', 'board');

        return in_array($configured, self::DISPLAY_TYPES, true) ? $configured : 'board';
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
            ->filter(fn (CustomField $field) => $viewer?->is_admin || $field->isVisibleToAllRoles())
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
