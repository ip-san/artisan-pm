<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NewsCommentCreated;
use App\Events\NewsCreated;
use App\Notifications\NewsNotification;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

final class SendNewsMailNotifications
{
    public function handle(NewsCreated|NewsCommentCreated $event): void
    {
        $isCreated = $event instanceof NewsCreated;
        $news = $isCreated ? $event->news : $event->comment->news;
        $actor = $isCreated ? $event->news->author : $event->comment->author;
        $eventKey = $isCreated ? 'news_added' : 'news_comment_added';

        $recipients = NotificationRecipients::forNews($news, $eventKey, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new NewsNotification($news, $isCreated ? 'added' : 'comment_added', $actor, $isCreated ? null : $event->comment),
        );
    }
}
