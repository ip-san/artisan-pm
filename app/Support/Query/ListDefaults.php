<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Models\Setting;

/**
 * Redmine's `issue_list_default_totals` and `time_entry_list_defaults`:
 * which totals a list shows, and the columns the time entry list starts
 * with. The defaults keep what the lists always did: estimated and spent
 * hours totalled on the issue list, every column and the hours total on the
 * time entry list.
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
        'spent_on' => '日付',
        'user_id' => '担当者',
        'activity_id' => '作業分類',
        'issue_id' => '課題',
        'comments' => 'コメント',
        'hours' => '時間',
    ];

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
     * @return array<int, string> keys of TIME_ENTRY_COLUMNS, in the configured order
     */
    public static function timeEntryColumns(): array
    {
        $configured = self::timeEntryDefaults()['column_names'] ?? null;
        $valid = is_array($configured)
            ? array_values(array_filter($configured, fn ($key) => is_string($key) && array_key_exists($key, self::TIME_ENTRY_COLUMNS)))
            : [];

        return $valid !== [] ? array_values(array_unique($valid)) : array_keys(self::TIME_ENTRY_COLUMNS);
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
