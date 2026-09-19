<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\ProjectEventNotificationMail;
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

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $headline,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $body = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ProjectEventNotificationMail
    {
        return (new ProjectEventNotificationMail($this->subjectLine, $this->headline, $this->title, $this->url, $this->body))
            ->to($notifiable->routeNotificationFor('mail', $this));
    }
}
