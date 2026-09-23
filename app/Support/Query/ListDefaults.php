<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Models\Setting;

/**
 * Redmine's `issue_list_default_totals` and `time_entry_list_defaults`:
 * which totals a list shows, and the columns the time entry list starts
 * with. The defaults keep what the lists always did: estimated and spent
 * hours totalled on the issue list, Redmine's six default columns and the
 * hours total on the time entry list.
 */
final class ListDefaults
{
    /**
     * The sums the issue list can total, by key.
     *
     * @var array<string, string>
     */
    public const array ISSUE_TOTALS = [
        'estimated_hours' => '予定工数',
        'spent_hours' => '実績工数',
        'estimated_remaining_hours' => '残り工数(予定)',
    ];

    /**
     * @var array<string, string>
     */
    public const array TIME_ENTRY_COLUMNS = [
        'project_id' => 'プロジェクト',
        'spent_on' => '日付',
        'created_at' => '作成日',
        'tweek' => '週',
        'author_id' => '作成者',
        'user_id' => 'ユーザー',
        'activity_id' => '作業分類',
        'issue_id' => '課題',
        'comments' => 'コメント',
        'hours' => '時間',
    ];

    /**
     * The columns a time entry list starts with when the setting names none
     * (Redmine's time_entry_list_defaults default).
     *
     * @var array<int, string>
     */
    public const array DEFAULT_TIME_ENTRY_COLUMNS = ['spent_on', 'user_id', 'activity_id', 'issue_id', 'comments', 'hours'];

    /**
     * ISSUE_TOTALS' labels, translated for display.
     *
     * @return array<string, string>
     */
    public static function issueTotalLabels(): array
    {
        return [
            'estimated_hours' => __('予定工数'),
            'spent_hours' => __('実績工数'),
            'estimated_remaining_hours' => __('残り工数(予定)'),
        ];
    }

    /**
     * TIME_ENTRY_COLUMNS' labels, translated for display.
     *
     * @return array<string, string>
     */
    public static function timeEntryColumnLabels(): array
    {
        return [
            'project_id' => __('プロジェクト'),
            'spent_on' => __('日付'),
            'created_at' => __('作成日'),
            'tweek' => __('週'),
            'author_id' => __('作成者'),
            'user_id' => __('ユーザー'),
            'activity_id' => __('作業分類'),
            'issue_id' => __('課題'),
            'comments' => __('コメント'),
            'hours' => __('時間'),
        ];
    }

    /**
     * @return array<int, string> keys of ISSUE_TOTALS, in that order
     */
    public static function issueTotals(): array
    {
        $configured = Setting::get('issue_list_default_totals', ['estimated_hours', 'spent_hours']);

        return array_values(array_filter(
            array_keys(self::ISSUE_TOTALS),
            fn (string $key) => is_array($configured) && in_array($key, $configured, true),
        ));
    }

    /**
     * @return array<int, string> keys of TIME_ENTRY_COLUMNS, in the configured order (DEFAULT_TIME_ENTRY_COLUMNS when none are)
     */
    public static function timeEntryColumns(): array
    {
        $configured = self::timeEntryDefaults()['column_names'] ?? null;
        $valid = is_array($configured)
            ? array_values(array_filter($configured, fn ($key) => is_string($key) && array_key_exists($key, self::TIME_ENTRY_COLUMNS)))
            : [];

        return $valid !== [] ? array_values(array_unique($valid)) : self::DEFAULT_TIME_ENTRY_COLUMNS;
    }

    public static function timeEntriesShowHoursTotal(): bool
    {
        $totalable = self::timeEntryDefaults()['totalable_names'] ?? ['hours'];

        return is_array($totalable) && in_array('hours', $totalable, true);
    }

    /**
     * @return array{column_names?: array<int, string>, totalable_names?: array<int, string>}
     */
    private static function timeEntryDefaults(): array
    {
        $stored = Setting::get('time_entry_list_defaults', []);

        return is_array($stored) ? $stored : [];
    }
}
