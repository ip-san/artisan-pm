<?php

declare(strict_types=1);

namespace App\Support\Issues;

/**
 * The issue list's own (non custom field) columns, in the order the
 * column picker offers them — shared by the list itself and the site's
 * default-columns setting. Custom fields join them keyed cf_{id}, the same
 * key convention the filter engine uses.
 */
final class IssueColumns
{
    /**
     * @return array<string, string> column key => label
     */
    public static function native(): array
    {
        return [
            'tracker_id' => __('トラッカー'),
            'status_id' => __('ステータス'),
            'priority_id' => __('優先度'),
            'subject' => __('題名'),
            'category_id' => __('カテゴリ'),
            'assigned_to_id' => __('担当者'),
            'author_id' => __('作成者'),
            'fixed_version_id' => __('対象バージョン'),
            'start_date' => __('開始日'),
            'due_date' => __('期日'),
            'created_at' => __('作成日'),
            'done_ratio' => __('進捗率'),
            'relations' => __('関連するチケット'),
            'attachments' => __('添付ファイル'),
            'watchers' => __('ウォッチャー'),
            'estimated_hours' => __('予定工数'),
            'total_estimated_hours' => __('合計予定工数'),
            'estimated_remaining_hours' => __('残り工数'),
            'spent_hours' => __('作業時間'),
            'total_spent_hours' => __('合計作業時間'),
            'project_id' => __('プロジェクト'),
            'parent_id' => __('親課題'),
            'parent_subject' => __('親課題の題名'),
            'updated_at' => __('更新日'),
            'closed_on' => __('終了日'),
            'last_updated_by' => __('最終更新者'),
            'is_private' => __('非公開'),
            'description' => __('説明'),
            'last_notes' => __('最新のコメント'),
        ];
    }
}
