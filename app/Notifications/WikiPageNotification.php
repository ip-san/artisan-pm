<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\WikiPageNotificationMail;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class WikiPageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly WikiPage $wikiPage,
        public readonly string $eventType,
        public readonly User $actor,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): WikiPageNotificationMail
    {
        return (new WikiPageNotificationMail($this->wikiPage, $this->eventType, $this->actor))
            ->to($notifiable->email);
    }
}
