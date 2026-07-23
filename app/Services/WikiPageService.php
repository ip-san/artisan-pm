<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\WikiPageCreated;
use App\Events\WikiPageDeleted;
use App\Events\WikiPageUpdated;
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
        $page = DB::transaction(function () use ($project, $attributes, $text, $author) {
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

        WikiPageCreated::dispatch($page);

        return $page;
    }

    /**
     * Only appends a new version when the text actually changed, so a
     * rename or reparent on its own doesn't create a no-op version entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(WikiPage $page, array $attributes, string $text, User $author, ?string $comment = null, bool $redirectExistingLinks = true): WikiPage
    {
        $page = DB::transaction(function () use ($page, $attributes, $text, $author, $comment, $redirectExistingLinks) {
            $oldTitle = $page->title;

            $page->fill($attributes);
            $page->save();

            if (isset($attributes['title']) && $attributes['title'] !== $oldTitle) {
                $this->handleRename($page, $page->project, $oldTitle, $page->project, $attributes['title'], $redirectExistingLinks);
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

        WikiPageUpdated::dispatch($page);

        return $page;
    }

    /**
     * Dispatched before the row is actually removed, so listeners (e.g.
     * the webhook payload builder) see a fully intact model — matches
     * IssueService::delete()'s same ordering rationale.
     */
    public function delete(WikiPage $page): void
    {
        WikiPageDeleted::dispatch($page);

        $page->delete();
    }

    /**
     * Moves a page to a different project's wiki — matches Redmine's
     * WikiPage#wiki_id safe_attribute (handled through the same
     * handle_rename_or_move callback as a title change there). The page's
     * own parent is always cleared, since a parent from the old project
     * essentially never also exists in the new one — mirrors Redmine's
     * implicit `self.parent_id = nil` when `parent.wiki_id != wiki_id`.
     * Unlike Redmine's handle_children_move, this page's own children are
     * NOT cascaded into the new project — they're detached to the top
     * level instead, the same deliberate scope cut already established by
     * IssueService::moveToProject() for issue subtasks.
     */
    public function moveToProject(WikiPage $page, Project $targetProject, bool $redirectExistingLinks = true): WikiPage
    {
        $oldProject = $page->project;
        $title = $page->title;

        $page = DB::transaction(function () use ($page, $oldProject, $targetProject, $title, $redirectExistingLinks) {
            $page->project()->associate($targetProject);
            $page->parent_id = null;
            $page->save();

            $this->handleRename($page, $oldProject, $title, $targetProject, $title, $redirectExistingLinks);

            WikiPage::query()->where('parent_id', $page->id)->update(['parent_id' => null]);

            return $page->refresh();
        });

        WikiPageUpdated::dispatch($page);

        return $page;
    }

    /**
     * Keeps "[[Old Title]]" links resolvable after a rename and/or a move
     * to another project — matches Redmine's WikiPage#handle_rename_or_move,
     * which runs the same redirect-chain maintenance for both. When the
     * project changes, `redirects_to_project_id` is set so the redirect can
     * point across projects; for a same-project rename it stays null and
     * resolves implicitly against the redirect's own project. See
     * WikiLinkInlineParser for the actual lookup fallback.
     */
    private function handleRename(WikiPage $page, Project $oldProject, string $oldTitle, Project $newProject, string $newTitle, bool $redirectExistingLinks): void
    {
        $movingProject = ! $oldProject->is($newProject);
        $redirectsToProjectId = $movingProject ? $newProject->id : null;

        $chainedRedirects = $oldProject->wikiRedirects()
            ->where('redirects_to', $oldTitle)
            ->where(function ($query) use ($oldProject) {
                $query->whereNull('redirects_to_project_id')->orWhere('redirects_to_project_id', $oldProject->id);
            });

        if (! $movingProject) {
            // A redirect that would become self-referential after
            // repointing is removed instead of updated.
            (clone $chainedRedirects)->where('title', $newTitle)->delete();
        }

        $chainedRedirects->update(['redirects_to' => $newTitle, 'redirects_to_project_id' => $redirectsToProjectId]);

        // The new title is now a live page in the destination project,
        // not a redirect source.
        $newProject->wikiRedirects()->where('title', $newTitle)->delete();

        if ($redirectExistingLinks) {
            WikiRedirect::create([
                'project_id' => $oldProject->id,
                'title' => $oldTitle,
                'redirects_to' => $newTitle,
                'redirects_to_project_id' => $redirectsToProjectId,
            ]);
        }
    }
}
