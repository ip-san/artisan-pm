<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Redmine's Mailer#account_information (the users API's and admin form's
 * send_information): the login, the password when one was set or
 * generated, and the login page. A directory-backed account is told to
 * sign in with its directory account instead.
 */
final class AccountInformation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $password = null,
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
        $message = (new MailMessage)->subject(__('[:app] アカウント情報', ['app' => $appTitle]));

        if ($notifiable instanceof User && $notifiable->auth_source_id !== null) {
            $message->line(__(':source のアカウントでログインできます。', ['source' => (string) $notifiable->authSource?->name]));
        } else {
            $message->line(__('アカウント情報:'))
                ->line(__('ログインID: :login', ['login' => $notifiable instanceof User ? $notifiable->login : '']));

            if ($this->password !== null) {
                $message->line(__('パスワード: :password', ['password' => $this->password]));
            }
        }

        return $message->action(__('ログイン'), route('login'));
    }
}
