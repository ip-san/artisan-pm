<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\NewsNotificationMail;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class NewsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly News $news,
        public readonly string $eventType,
        public readonly User $actor,
        public readonly ?NewsComment $comment = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): NewsNotificationMail
    {
        return (new NewsNotificationMail($this->news, $this->eventType, $this->actor, $this->comment))
            ->to($notifiable->email);
    }
}
