<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\Setting;

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
        'done_ratio' => '進捗率',
    ];

    /**
     * Redmine's config/settings.yml default.
     *
     * @var array<int, string>
     */
    public const array DEFAULT = ['status_id', 'assigned_to_id', 'start_date', 'due_date', 'done_ratio'];

    /**
     * The configured columns, in AVAILABLE order, ignoring anything no
     * longer selectable.
     *
     * @return array<string, string> key => label
     */
    public static function selected(): array
    {
        $configured = Setting::get('related_issues_default_columns', self::DEFAULT);

        return array_intersect_key(
            self::AVAILABLE,
            array_flip(is_array($configured) ? $configured : self::DEFAULT),
        );
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
            'status_id' => 'status',
            'priority_id' => 'priority',
            'category_id' => 'category',
            'assigned_to_id' => 'assignedTo',
            'author_id' => 'author',
            'fixed_version_id' => 'fixedVersion',
        ];

        return array_values(array_intersect_key($map, array_flip($keys)));
    }

    /**
     * The text of one column for an issue; relations must already be
     * loaded (see relationsFor()).
     */
    public static function value(Issue $issue, string $key): string
    {
        return match ($key) {
            'status_id' => $issue->status->name,
            'priority_id' => $issue->priority->name,
            'category_id' => $issue->category?->name ?? '',
            'assigned_to_id' => $issue->assignedTo?->name ?? '',
            'author_id' => $issue->author->name,
            'fixed_version_id' => $issue->fixedVersion?->name ?? '',
            'start_date' => $issue->start_date?->toDateString() ?? '',
            'due_date' => $issue->due_date?->toDateString() ?? '',
            'created_at' => $issue->created_at->toDateString(),
            'done_ratio' => "{$issue->done_ratio}%",
            default => '',
        };
    }
}
