<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\ProjectEventNotificationMail;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The mail for the smaller project events (a forum post, a document, files
 * uploaded): each has a subject, a one-line headline, an optional body and a
 * link, so one notification covers them all.
 */
final class ProjectEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'message_posted'|'document_added'|'file_added'|'version_file_added'  $kind
     * @param  array<string, string|int>  $replace  the values for the kind's subject and headline
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $replace,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $body = null,
        public readonly ?Message $threadMessage = null,
    ) {}

    /**
     * Built when the mail is sent, so in the recipient's language
     * (User::preferredLocale()).
     */
    public function subjectLine(): string
    {
        return match ($this->kind) {
            'message_posted' => __('[:project - :board - msg:id] :subject', $this->replace),
            'document_added' => __('[:project] 文書を追加しました: :title', $this->replace),
            default => __('[:project] ファイルを追加しました: :names', $this->replace),
        };
    }

    public function headline(): string
    {
        return match ($this->kind) {
            'message_posted' => __(':author さんがフォーラムに投稿しました。', $this->replace),
            'document_added' => __(':author さんが文書を追加しました。', $this->replace),
            'version_file_added' => __(':author さんがバージョン「:version」にファイルを追加しました。', $this->replace),
            default => __(':author さんがプロジェクトにファイルを追加しました。', $this->replace),
        };
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ProjectEventNotificationMail
    {
        return (new ProjectEventNotificationMail($this->subjectLine(), $this->headline(), $this->title, $this->url, $this->body, $this->threadMessage))
            ->to($notifiable->routeNotificationFor('mail', $this))
            ->forRecipient($notifiable);
    }
}
