<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;

/**
 * The options of the filter builder's "add filter" select, grouped the way
 * Redmine 7.0's QueriesHelper#filters_options_for_select groups them into
 * optgroups: filters on an associated record (Redmine's dotted names such
 * as fixed_version.due_date) under that record, relation and tree filters
 * under "relations", the assignee's group/role under the assignee, dates,
 * time tracking, attachments and text filters each in their own group, and
 * everything else ungrouped at the top.
 */
final class FilterSelectOptions
{
    /**
     * The filters Redmine names "<association>.<field>". Their keys here
     * use an underscore instead of the dot (a dot would be read as a nested
     * path by the lists' Livewire/URL state).
     *
     * @var array<string, string> key => Redmine's dotted name
     */
    public const DOTTED_KEYS = [
        'fixed_version_due_date' => 'fixed_version.due_date',
        'fixed_version_status' => 'fixed_version.status',
        'project_status' => 'project.status',
        'author_group' => 'author.group',
        'author_role' => 'author.role',
        'issue_tracker_id' => 'issue.tracker_id',
        'issue_parent_id' => 'issue.parent_id',
        'issue_status_id' => 'issue.status_id',
        'issue_fixed_version_id' => 'issue.fixed_version_id',
        'issue_category_id' => 'issue.category_id',
        'issue_subject' => 'issue.subject',
        'user_group' => 'user.group',
        'user_role' => 'user.role',
    ];

    /**
     * @param  iterable<FilterableField>  $fields
     * @param  array<int, string>  $excludedKeys  filters already in use, which the select no longer offers
     * @return array{ungrouped: array<string, string>, groups: array<string, array<string, string>>}
     */
    public static function grouped(iterable $fields, array $excludedKeys = []): array
    {
        $ungrouped = [];
        $groups = [];
        $order = ['string' => __('文字列'), 'date' => __('日付'), 'time_tracking' => __('時間管理'), 'attachment' => __('添付ファイル')];
        $grouped = array_fill_keys(array_keys($order), []);
        $associationLabels = [];

        foreach ($fields as $field) {
            if (in_array($field->key(), $excludedKeys, true)) {
                continue;
            }

            $group = self::groupOf($field);

            if ($group === null) {
                $ungrouped[$field->key()] = $field->label();

                continue;
            }

            if (str_starts_with($group, 'association:')) {
                $associationLabels[$group] = self::associationLabel(substr($group, strlen('association:')));
            }

            $grouped[$group][$field->key()] = $field->label();
        }

        // As in Redmine, a lone date filter is not worth a group of its own.
        if (count($grouped['date']) === 1) {
            $ungrouped += $grouped['date'];
            $grouped['date'] = [];
        }

        foreach ($grouped as $group => $options) {
            if ($options === []) {
                continue;
            }

            $groups[$order[$group] ?? $associationLabels[$group] ?? $group] = $options;
        }

        return ['ungrouped' => $ungrouped, 'groups' => $groups];
    }

    /**
     * The associations whose custom fields are offered as
     * "<association>_cf_N" filters (project.cf_N, author.cf_N,
     * assigned_to.cf_N, fixed_version.cf_N in Redmine — see
     * IssueExtraFilterFields::relatedCustomFieldFilters() and
     * TimeEntryExtraFilterFields' project_cf_N/user_cf_N). Unlike
     * DOTTED_KEYS, these keys carry a variable custom_field_id, so they are
     * matched by prefix rather than listed one by one.
     */
    private const RELATED_CUSTOM_FIELD_PREFIXES = ['project_cf_', 'author_cf_', 'assigned_to_cf_', 'fixed_version_cf_', 'user_cf_'];

    private static function groupOf(FilterableField $field): ?string
    {
        $key = $field->key();

        foreach (self::RELATED_CUSTOM_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix) && ctype_digit(substr($key, strlen($prefix)))) {
                return 'association:'.substr($prefix, 0, -strlen('_cf_'));
            }
        }

        return match (true) {
            isset(self::DOTTED_KEYS[$key]) => 'association:'.strstr(self::DOTTED_KEYS[$key], '.', true),
            in_array(FilterOperator::AnyOpenIssues, $field->operators(), true), in_array($key, ['parent_id', 'child_id'], true) => 'association:relations',
            in_array($key, ['member_of_group', 'assigned_to_role'], true) => 'association:assigned_to',
            $field->type() === FilterFieldType::Date => 'date',
            in_array($key, ['estimated_hours', 'spent_time'], true) => 'time_tracking',
            in_array($key, ['attachment', 'attachment_description'], true) => 'attachment',
            $field->type() === FilterFieldType::Text => 'string',
            default => null,
        };
    }

    private static function associationLabel(string $association): string
    {
        return match ($association) {
            'relations' => __('関係'),
            'assigned_to' => __('担当者'),
            'fixed_version' => __('対象バージョン'),
            'project' => __('プロジェクト'),
            'author' => __('作成者'),
            'issue' => __('課題'),
            'user' => __('ユーザー'),
            default => $association,
        };
    }
}
