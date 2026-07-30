<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-user mail notification preference — matches Redmine's
 * User::MAIL_NOTIFICATION_OPTIONS. Redmine's `selected` tier (notify only
 * for a hand-picked subset of projects, tracked on the Member row) is
 * deliberately not reproduced here — it needs a per-membership toggle UI
 * that doesn't exist yet, so `Selected` falls back to the same audience
 * as OnlyMyEvents in App\Support\Mail\NotificationRecipients (documented
 * there too).
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
            self::Selected => '選択したプロジェクトのみ通知(未対応、「自分の関与するイベントのみ」として扱われます)',
            self::OnlyMyEvents => '自分の関与するイベントのみ通知(作成者・担当者・ウォッチャー)',
            self::OnlyAssigned => '自分が担当者のイベントのみ通知',
            self::OnlyOwner => '自分が作成者のイベントのみ通知',
            self::None => '通知しない',
        };
    }
}
