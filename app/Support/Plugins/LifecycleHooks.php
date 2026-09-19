<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use App\Events\DocumentAdded;
use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueJournalRecorded;
use App\Events\IssueUpdated;
use App\Events\MessagePosted;
use App\Events\NewsCommentCreated;
use App\Events\NewsCreated;
use App\Events\NewsDeleted;
use App\Events\NewsUpdated;
use App\Events\ProjectFilesAdded;
use App\Events\TimeEntryCreated;
use App\Events\TimeEntryDeleted;
use App\Events\TimeEntryUpdated;
use App\Events\VersionCreated;
use App\Events\VersionDeleted;
use App\Events\VersionUpdated;
use App\Events\WikiPageCreated;
use App\Events\WikiPageDeleted;
use App\Events\WikiPageUpdated;

/**
 * The events a plugin can subscribe to — this app's counterpart of Redmine's
 * `controller_*` / `model_*` hooks. They are ordinary Laravel events fired by
 * the services after the change is saved (so a hook sees the finished issue,
 * not a half-built one); `PluginManager::onLifecycle()` subscribes to one by
 * its key. Redmine's *before_save* hooks, which can veto or alter a save, have
 * no equivalent: the events are notifications only.
 *
 * Adding an event class under app/Events means adding it here (a test checks).
 */
final class LifecycleHooks
{
    /**
     * @return array<string, array{event: class-string, description: string}> keyed by hook name
     */
    public static function catalog(): array
    {
        return [
            'issue.created' => ['event' => IssueCreated::class, 'description' => '課題が作成された(作成後)'],
            'issue.updated' => ['event' => IssueUpdated::class, 'description' => '課題の属性・コメントが更新された(保存後)'],
            'issue.journal_recorded' => ['event' => IssueJournalRecorded::class, 'description' => '課題にコメントだけが追加された'],
            'issue.deleted' => ['event' => IssueDeleted::class, 'description' => '課題が削除される直前(モデルはまだ完全)'],
            'time_entry.created' => ['event' => TimeEntryCreated::class, 'description' => '工数が記録された'],
            'time_entry.updated' => ['event' => TimeEntryUpdated::class, 'description' => '工数が更新された'],
            'time_entry.deleted' => ['event' => TimeEntryDeleted::class, 'description' => '工数が削除される直前'],
            'wiki_page.created' => ['event' => WikiPageCreated::class, 'description' => 'Wikiページが作成された'],
            'wiki_page.updated' => ['event' => WikiPageUpdated::class, 'description' => 'Wikiページが更新された'],
            'wiki_page.deleted' => ['event' => WikiPageDeleted::class, 'description' => 'Wikiページが削除される直前'],
            'news.created' => ['event' => NewsCreated::class, 'description' => 'お知らせが投稿された'],
            'news.updated' => ['event' => NewsUpdated::class, 'description' => 'お知らせが更新された'],
            'news.deleted' => ['event' => NewsDeleted::class, 'description' => 'お知らせが削除される直前'],
            'news.comment_created' => ['event' => NewsCommentCreated::class, 'description' => 'お知らせにコメントが投稿された'],
            'version.created' => ['event' => VersionCreated::class, 'description' => 'バージョンが作成された'],
            'version.updated' => ['event' => VersionUpdated::class, 'description' => 'バージョンが更新された'],
            'version.deleted' => ['event' => VersionDeleted::class, 'description' => 'バージョンが削除される直前'],
            'message.posted' => ['event' => MessagePosted::class, 'description' => 'フォーラムにメッセージが投稿された'],
            'document.added' => ['event' => DocumentAdded::class, 'description' => '文書が追加された'],
            'project_files.added' => ['event' => ProjectFilesAdded::class, 'description' => 'プロジェクト/バージョンにファイルが追加された'],
        ];
    }

    /**
     * @return class-string|null
     */
    public static function eventFor(string $hook): ?string
    {
        return self::catalog()[$hook]['event'] ?? null;
    }
}
