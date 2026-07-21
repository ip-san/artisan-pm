<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiRedirect;
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
    public function update(WikiPage $page, array $attributes, string $text, User $author, ?string $comment = null, bool $redirectExistingLinks = true): WikiPage
    {
        return DB::transaction(function () use ($page, $attributes, $text, $author, $comment, $redirectExistingLinks) {
            $oldTitle = $page->title;

            $page->fill($attributes);
            $page->save();

            if (isset($attributes['title']) && $attributes['title'] !== $oldTitle) {
                $this->handleRename($page, $oldTitle, $attributes['title'], $redirectExistingLinks);
            }

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

    /**
     * Keeps "[[Old Title]]" links resolvable after a rename — matches
     * Redmine's WikiPage#handle_rename_or_move. WikiLinkInlineParser is
     * where the actual lookup fallback lives; this only maintains the
     * redirect chain data.
     */
    private function handleRename(WikiPage $page, string $oldTitle, string $newTitle, bool $redirectExistingLinks): void
    {
        // Existing redirects that pointed at the old title now chain
        // forward to the new one — unless that would make a redirect
        // point at itself, in which case it's just removed.
        $page->project->wikiRedirects()
            ->where('redirects_to', $oldTitle)
            ->where('title', $newTitle)
            ->delete();

        $page->project->wikiRedirects()
            ->where('redirects_to', $oldTitle)
            ->update(['redirects_to' => $newTitle]);

        // The new title is now a live page, not a redirect source.
        $page->project->wikiRedirects()->where('title', $newTitle)->delete();

        if ($redirectExistingLinks) {
            WikiRedirect::create([
                'project_id' => $page->project_id,
                'title' => $oldTitle,
                'redirects_to' => $newTitle,
            ]);
        }
    }
}
