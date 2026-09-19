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
            self::All => 'すべてのイベントを通知',
            self::Selected => '選択したプロジェクトのイベントと、自分の関与するイベントのみ通知',
            self::OnlyMyEvents => '自分の関与するイベントのみ通知(作成者・担当者・ウォッチャー)',
            self::OnlyAssigned => '自分が担当者のイベントのみ通知',
            self::OnlyOwner => '自分が作成者のイベントのみ通知',
            self::None => '通知しない',
        };
    }
}
