<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Changeset;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Watcher;
use App\Support\Attachments\AttachmentUploader;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property Issue $resource
 */
final class IssueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $issue = $this->resource;

        return [
            'id' => $issue->id,
            'project_id' => $issue->project_id,
            'tracker_id' => $issue->tracker_id,
            'status_id' => $issue->status_id,
            'priority_id' => $issue->priority_id,
            'author_id' => $issue->author_id,
            'assigned_to_id' => $issue->assigned_to_id,
            'fixed_version_id' => $issue->fixed_version_id,
            'parent_id' => $issue->parent_id,
            'subject' => $issue->subject,
            'description' => $issue->description,
            'start_date' => $issue->start_date?->toDateString(),
            'due_date' => $issue->due_date?->toDateString(),
            'done_ratio' => $issue->done_ratio,
            'lock_version' => $issue->lock_version,
            'created_at' => $issue->created_at->toIso8601String(),
            'updated_at' => $issue->updated_at->toIso8601String(),

            // Each of these is only present when the caller asked for it
            // via ?include=journals,relations,attachments,children,watchers
            // (Redmine's own include= keys, minus allowed_statuses/
            // changesets — out of scope here) and the controller eager
            // loaded the matching relation accordingly.
            'journals' => $this->whenLoaded('journals', fn () => $this->visibleJournals($issue, $request)),
            'relations' => $this->when(
                $issue->relationLoaded('relationsFrom') || $issue->relationLoaded('relationsTo'),
                fn () => $this->visibleRelations($issue, $request),
            ),
            'attachments' => $this->whenLoaded('media', fn () => $this->attachments($issue)),
            'children' => $this->whenLoaded('children', fn () => $this->children($issue, $request)),
            'watchers' => $this->whenLoaded('watchers', fn () => $this->watchers($issue)),
            // Not relations, so the controller records the request in the
            // request attributes (see IssueController::show()).
            'allowed_statuses' => $this->when(
                $this->includes($request, 'allowed_statuses'),
                fn () => $this->allowedStatuses($issue, $request),
            ),
            'changesets' => $this->when(
                $this->includes($request, 'changesets') && $issue->relationLoaded('changesets'),
                fn () => $this->changesets($issue, $request),
            ),
        ];
    }

    /**
     * A private journal entry is only included for a user who holds
     * view_private_notes on the project, or who wrote it themselves —
     * matches Redmine's Issue#visible_journals_with_index, and the exact
     * same filter issues/show.blade.php's own visibleJournals() computed
     * property already applies on the web UI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visibleJournals(Issue $issue, Request $request): array
    {
        $user = $request->user();
        $journals = $issue->journals;

        if (! $user?->can('viewPrivateNotes', $issue)) {
            $journals = $journals->filter(
                fn (Journal $journal) => ! $journal->private_notes || $journal->user_id === $user?->id
            );
        }

        return $journals->values()->map(fn (Journal $journal) => [
            'id' => $journal->id,
            'user' => ['id' => $journal->user_id, 'name' => $journal->user->name],
            'notes' => $journal->notes,
            'private_notes' => $journal->private_notes,
            'created_at' => $journal->created_at->toIso8601String(),
        ])->all();
    }

    /**
     * Both directions (relationsFrom/relationsTo) merged into one list,
     * each filtered to relations whose *other* issue the current user can
     * actually view — matches Redmine's
     * `@issue.relations.select {|r| r.other_issue(@issue)&.visible?}`, so
     * a relation can't be used to infer the existence/subject of an issue
     * in a project the caller has no access to.
     *
     * Bumped to public (rather than the other embed helpers, kept
     * private) so IssueRelationController can reuse this exact same
     * filter for the dedicated relations endpoint instead of
     * reimplementing it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleRelations(Issue $issue, Request $request): array
    {
        $user = $request->user();

        $from = $issue->relationsFrom->filter(fn (IssueRelation $relation) => $relation->to !== null && $user?->can('view', $relation->to));
        $to = $issue->relationsTo->filter(fn (IssueRelation $relation) => $relation->from !== null && $user?->can('view', $relation->from));

        return $from->concat($to)->values()->map(fn (IssueRelation $relation) => [
            'id' => $relation->id,
            'issue_id' => $relation->issue_from_id,
            'issue_to_id' => $relation->issue_to_id,
            'relation_type' => $relation->relation_type->value,
            'delay' => $relation->delay,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Issue $issue): array
    {
        $medias = $issue->getMedia('attachments');

        // One lookup for every uploader instead of one per attachment.
        request()->attributes->set('attachment_uploaders', AttachmentUploader::usersFor($medias));

        return $medias->map(fn (Media $media) => (new AttachmentResource($media))->resolve(request()))->values()->all();
    }

    private function includes(Request $request, string $key): bool
    {
        return in_array($key, $request->attributes->get('issue_api_includes', []), true);
    }

    /**
     * The statuses the caller may move this issue to, plus its current one
     * — Redmine's `new_statuses_allowed_to`, the same list the edit form
     * offers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function allowedStatuses(Issue $issue, Request $request): array
    {
        return app(WorkflowService::class)->allowedTransitions($issue, $request->user())
            ->push($issue->status)
            ->unique('id')
            ->sortBy('position')
            ->map(fn (IssueStatus $status) => ['id' => $status->id, 'name' => $status->name, 'is_closed' => $status->is_closed])
            ->values()
            ->all();
    }

    /**
     * Commits linked to the issue, limited to repositories whose project the
     * caller may view changesets in (an issue can be referenced from a
     * commit of another project). Redmine also resolves the committer to a
     * user; this app keeps the committer as the SCM's own text.
     *
     * @return array<int, array<string, mixed>>
     */
    private function changesets(Issue $issue, Request $request): array
    {
        return $issue->changesets
            ->filter(fn (Changeset $changeset) => $request->user()?->can('view', $changeset->repository))
            ->sortBy('committed_on')
            ->map(fn (Changeset $changeset) => [
                'revision' => $changeset->revision,
                'committer' => $changeset->committer,
                'comments' => $changeset->comments,
                'committed_on' => $changeset->committed_on->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The issue's children, each with its own `children` recursively like
     * Redmine's render_api_issue_children, keeping only the ones the caller
     * may see (a subtask can be private even when its parent is not).
     * Descendants must be eager-loaded (IssueController does); a level
     * without loaded children is treated as a leaf.
     *
     * @return array<int, array<string, mixed>>
     */
    private function children(Issue $issue, Request $request, int $depth = 0): array
    {
        return $issue->children
            ->filter(fn (Issue $child) => $request->user()?->can('view', $child))
            ->map(fn (Issue $child) => [
                'id' => $child->id,
                'tracker_id' => $child->tracker_id,
                'subject' => $child->subject,
                ...($depth < 25 && $child->relationLoaded('children') && $child->children->isNotEmpty()
                    ? ['children' => $this->children($child, $request, $depth + 1)]
                    : []),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function watchers(Issue $issue): array
    {
        return $issue->watchers->map(fn (Watcher $watcher) => [
            'id' => $watcher->user_id,
            'name' => $watcher->user->name,
        ])->values()->all();
    }
}
