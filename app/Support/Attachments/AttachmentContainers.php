<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\Document;
use App\Models\Issue;
use App\Models\Message;
use App\Models\News;
use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/**
 * The records whose 'attachments' collection the bulk download and bulk
 * edit pages work on, addressed by their morph-map alias in the URL —
 * Redmine's /attachments/:object_type/:object_id routes.
 */
final class AttachmentContainers
{
    /**
     * @var array<string, class-string<Model&HasMedia>>
     */
    private const array TYPES = [
        'issue' => Issue::class,
        'news' => News::class,
        'document' => Document::class,
        'wiki_page' => WikiPage::class,
        'message' => Message::class,
    ];

    public static function find(string $type, int $id): (Model&HasMedia)|null
    {
        $class = self::TYPES[$type] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }

    /**
     * The page the record lives on, where the bulk pages send you back to.
     */
    public static function url(Model $container): string
    {
        return match (true) {
            $container instanceof Issue => route('issues.show', [$container->project, $container]),
            $container instanceof News => route('news.show', [$container->project, $container]),
            $container instanceof Document => route('documents.show', [$container->project, $container]),
            $container instanceof WikiPage => route('wiki.show', [$container->project, $container]),
            $container instanceof Message => route('messages.show', [$container->board->project, $container->board, $container->isTopic() ? $container : $container->parent_id]),
            default => route('projects.index'),
        };
    }

    public static function aliasFor(Model $container): string
    {
        return array_search($container::class, self::TYPES, true) ?: $container->getMorphClass();
    }
}
