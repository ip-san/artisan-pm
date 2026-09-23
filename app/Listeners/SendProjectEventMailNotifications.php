<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DocumentAdded;
use App\Events\MessagePosted;
use App\Events\ProjectFilesAdded;
use App\Models\User;
use App\Models\Version;
use App\Notifications\ProjectEventNotification;
use App\Support\Mail\MailSuppression;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Collection;
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
        if (MailSuppression::active()) {
            return;
        }

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
     * @return array{0: Collection<int, User>, 1: ProjectEventNotification}
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
                'message_posted',
                ['project' => $project->name, 'board' => $message->board->name, 'id' => $topic->id, 'subject' => $message->subject, 'author' => $message->author->displayName()],
                $message->subject,
                route('messages.show', [$project, $message->board, $topic]),
                $message->content,
            ),
        ];
    }

    /**
     * @return array{0: Collection<int, User>, 1: ProjectEventNotification}
     */
    private function forDocument(DocumentAdded $event): array
    {
        $document = $event->document->loadMissing('project');

        return [
            NotificationRecipients::forProjectEvent($document->project, 'document_added', $event->actor, fn ($user) => $user->can('view', $document)),
            new ProjectEventNotification(
                'document_added',
                ['project' => $document->project->name, 'title' => $document->title, 'author' => $event->actor->name],
                $document->title,
                route('documents.show', [$document->project, $document]),
                $document->description,
            ),
        ];
    }

    /**
     * @return array{0: Collection<int, User>, 1: ProjectEventNotification}
     */
    private function forFiles(ProjectFilesAdded $event): array
    {
        $names = implode(', ', $event->fileNames);

        return [
            NotificationRecipients::forProjectEvent($event->project, 'file_added', $event->actor, fn ($user) => $user->can('viewAny', [Version::class, $event->project])),
            new ProjectEventNotification(
                $event->versionName !== null ? 'version_file_added' : 'file_added',
                ['project' => $event->project->name, 'names' => $names, 'author' => $event->actor->name, 'version' => (string) $event->versionName],
                $names,
                route('files.index', $event->project),
            ),
        ];
    }
}
