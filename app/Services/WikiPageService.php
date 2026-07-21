<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates WikiPage content, always through an append-only
 * WikiPageVersion row rather than editing content in place — mirrors
 * Redmine's WikiContent/WikiContentVersion split, collapsed here into a
 * single versions table since the "current" row is just the highest
 * version number.
 */
final class WikiPageService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes, string $text, User $author): WikiPage
    {
        return DB::transaction(function () use ($project, $attributes, $text, $author) {
            $page = new WikiPage($attributes);
            $page->project()->associate($project);
            $page->save();

            $page->versions()->create([
                'author_id' => $author->id,
                'text' => $text,
                'version' => 1,
            ]);

            return $page->refresh();
        });
    }

    /**
     * Only appends a new version when the text actually changed, so a
     * rename or reparent on its own doesn't create a no-op version entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(WikiPage $page, array $attributes, string $text, User $author, ?string $comment = null): WikiPage
    {
        return DB::transaction(function () use ($page, $attributes, $text, $author, $comment) {
            $page->fill($attributes);
            $page->save();

            if ($text !== $page->currentVersion?->text) {
                $nextVersion = ($page->versions()->max('version') ?? 0) + 1;

                $page->versions()->create([
                    'author_id' => $author->id,
                    'text' => $text,
                    'comments' => $comment,
                    'version' => $nextVersion,
                ]);

                $page->unsetRelation('currentVersion');
            }

            return $page->refresh();
        });
    }
}
