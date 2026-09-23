<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-user mail notification preference — matches Redmine's
 * User::MAIL_NOTIFICATION_OPTIONS. `Selected` notifies for the projects the
 * user ticked (members.mail_notification) plus the events they are involved
 * in anywhere — see App\Support\Mail\NotificationRecipients.
 */
enum MailNotificationOption: string
{
    case All = 'all';
    case Selected = 'selected';
    case OnlyMyEvents = 'only_my_events';
    case OnlyAssigned = 'only_assigned';
    case OnlyOwner = 'only_owner';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::All => __('すべてのイベントを通知'),
            self::Selected => __('選択したプロジェクトのイベントと、自分の関与するイベントのみ通知'),
            self::OnlyMyEvents => __('自分の関与するイベントのみ通知(作成者・担当者・ウォッチャー)'),
            self::OnlyAssigned => __('自分が担当者のイベントのみ通知'),
            self::OnlyOwner => __('自分が作成者のイベントのみ通知'),
            self::None => __('通知しない'),
        };
    }
}
