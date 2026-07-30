<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\IssueNotificationMail;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class IssueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Issue $issue,
        public readonly string $eventType,
        public readonly User $actor,
        public readonly ?Journal $journal = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): IssueNotificationMail
    {
        // MailChannel sends a Mailable-returning toMail() as-is via
        // Mailable::send() rather than auto-addressing it from the
        // notifiable the way it does for a MailMessage, so the
        // recipient has to be set here explicitly.
        return (new IssueNotificationMail($this->issue, $this->eventType, $this->actor, $this->journal))
            ->to($notifiable->email);
    }
}
