<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Matches Redmine's Mailer#register: a single link, no attachments/rich
 * content, so this uses Laravel's built-in MailMessage rather than a
 * dedicated Mailable + Blade view pair like the Issue/Wiki/News
 * notifications (which need custom layouts for changesets/diffs/etc.).
 */
final class ConfirmAccountRegistration extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $activationUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appTitle = Setting::get('app_title', config('app.name'));

        return (new MailMessage)
            ->subject("[{$appTitle}] アカウント登録の確認")
            ->line('アカウント登録を受け付けました。以下のリンクからアカウントを有効化してください。')
            ->action('アカウントを有効化', $this->activationUrl)
            ->line('このリンクの有効期限は24時間です。');
    }
}
