<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\Watcher;
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
            'children' => $this->whenLoaded('children', fn () => $this->children($issue)),
            'watchers' => $this->whenLoaded('watchers', fn () => $this->watchers($issue)),
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
        return $issue->getMedia('attachments')->map(fn (Media $media) => [
            'id' => $media->id,
            'filename' => $media->file_name,
            'filesize' => $media->size,
            'content_type' => $media->mime_type,
            'description' => $media->getCustomProperty('description'),
            'content_url' => route('attachments.show', $media),
            'created_at' => $media->created_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Direct children only, one level deep — unlike Redmine's own
     * infinitely-recursive render_api_issue_children, kept flat to match
     * this resource's existing convention of not embedding nested
     * resource graphs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function children(Issue $issue): array
    {
        return $issue->children->map(fn (Issue $child) => [
            'id' => $child->id,
            'tracker_id' => $child->tracker_id,
            'subject' => $child->subject,
        ])->values()->all();
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
