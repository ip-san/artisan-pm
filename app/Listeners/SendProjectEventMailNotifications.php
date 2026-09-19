<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DocumentAdded;
use App\Events\MessagePosted;
use App\Events\ProjectFilesAdded;
use App\Notifications\ProjectEventNotification;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

/**
 * Mail for Redmine's `message_posted`, `document_added` and `file_added`
 * events, sent to the recipients NotificationRecipients::forProjectEvent()
 * picks for the event key (and only if that key is switched on).
 */
final class SendProjectEventMailNotifications
{
    public function handle(MessagePosted|DocumentAdded|ProjectFilesAdded $event): void
    {
        $notification = match (true) {
            $event instanceof MessagePosted => $this->forMessage($event),
            $event instanceof DocumentAdded => $this->forDocument($event),
            default => $this->forFiles($event),
        };

        [$recipients, $mail] = $notification;

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $mail);
        }
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, \App\Models\User>, 1: ProjectEventNotification}
     */
    private function forMessage(MessagePosted $event): array
    {
        $message = $event->message->loadMissing(['board.project', 'author', 'parent']);
        $topic = $message->parent ?? $message;
        $project = $message->board->project;

        return [
            NotificationRecipients::forProjectEvent(
                $project, 'message_posted', $message->author,
                fn ($user) => $user->can('view', $message),
                $topic->watchers()->pluck('user_id'),
            ),
            new ProjectEventNotification(
                sprintf('[%s - %s #%d] %s', $project->name, $message->board->name, $topic->id, $message->subject),
                $message->author->name.' さんがフォーラムに投稿しました。',
                $message->subject,
                route('messages.show', [$project, $message->board, $topic]),
                $message->content,
            ),
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, \App\Models\User>, 1: ProjectEventNotification}
     */
    private function forDocument(DocumentAdded $event): array
    {
        $document = $event->document->loadMissing('project');

        return [
            NotificationRecipients::forProjectEvent($document->project, 'document_added', $event->actor, fn ($user) => $user->can('view', $document)),
            new ProjectEventNotification(
                sprintf('[%s] 文書を追加しました: %s', $document->project->name, $document->title),
                $event->actor->name.' さんが文書を追加しました。',
                $document->title,
                route('documents.show', [$document->project, $document]),
                $document->description,
            ),
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, \App\Models\User>, 1: ProjectEventNotification}
     */
    private function forFiles(ProjectFilesAdded $event): array
    {
        $names = implode(', ', $event->fileNames);
        $where = $event->versionName !== null ? "バージョン「{$event->versionName}」" : 'プロジェクト';

        return [
            NotificationRecipients::forProjectEvent($event->project, 'file_added', $event->actor, fn ($user) => $user->can('viewAny', [\App\Models\Version::class, $event->project])),
            new ProjectEventNotification(
                sprintf('[%s] ファイルを追加しました: %s', $event->project->name, $names),
                "{$event->actor->name} さんが{$where}にファイルを追加しました。",
                $names,
                route('files.index', $event->project),
            ),
        ];
    }
}
