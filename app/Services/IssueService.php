<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueUpdated;
use App\Exceptions\StaleIssueUpdateException;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Applies changes to an Issue and records a Journal entry for the diff in
 * the same operation — Redmine's Journal is simultaneously an audit trail
 * and a user-authored comment thread, so both are written together here
 * rather than relying on a model observer that can't see the comment text.
 */
final class IssueService
{
    /**
     * Attributes tracked in the journal when they change.
     *
     * @var array<string>
     */
    private const JOURNALED_ATTRIBUTES = [
        'project_id', 'tracker_id', 'status_id', 'priority_id', 'category_id', 'subject',
        'description', 'assigned_to_id', 'fixed_version_id', 'parent_id',
        'start_date', 'due_date', 'done_ratio', 'estimated_hours', 'is_private',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $customFieldData  custom_field_id => raw input
     */
    public function create(array $attributes, User $author, array $customFieldData = []): Issue
    {
        $issue = new Issue;
        $issue->fill($attributes);
        $issue->author_id = $author->id;
        $this->applyStatusDoneRatio($issue);
        $issue->save();

        $issue->setCustomFieldValues($customFieldData);

        $this->autoWatch($issue, $issue->author_id);
        $this->autoWatch($issue, $issue->assigned_to_id);

        $this->recalculateAncestorAttributes($issue->parent_id);

        IssueCreated::dispatch($issue);

        return $issue;
    }

    /**
     * Dispatched before the row is actually removed, so listeners (e.g.
     * the webhook payload builder, which serializes the issue's own
     * attributes) see a fully intact model — deleting first would still
     * leave those attributes readable in PHP, but there's no reason to
     * rely on that.
     */
    public function delete(Issue $issue): void
    {
        IssueDeleted::dispatch($issue);

        $issue->delete();
    }

    /**
     * Records an attachment being added to or removed from an existing
     * issue as its own journal — Redmine's Journal#journalize_attachment
     * (property 'attachment', prop_key = attachment id, filename in the
     * value on add / the old value on removal). Files uploaded while
     * creating an issue are deliberately not journaled: matching
     * Redmine, where creation produces no journal at all.
     */
    public function journalizeAttachment(Issue $issue, Media $media, bool $added, User $actor): void
    {
        $journal = Journal::create([
            'issue_id' => $issue->id,
            'user_id' => $actor->id,
            'notes' => null,
        ]);

        $journal->details()->create([
            'property' => 'attachment',
            'prop_key' => (string) $media->id,
            'old_value' => $added ? null : $media->file_name,
            'new_value' => $added ? $media->file_name : null,
        ]);
    }

    /**
     * Records a relation change on BOTH issues — matching Redmine's
     * journalize_relation, each end gets its own journal whose prop_key
     * is the relation type as seen from that issue (the reversed name
     * on the receiving end: blocks→blocked, precedes→follows, ...) and
     * whose value is the other issue's id.
     */
    public function journalizeRelation(IssueRelation $relation, bool $added, User $actor): void
    {
        $type = $relation->relation_type->value;

        $reversedType = match ($type) {
            'blocks' => 'blocked',
            'duplicates' => 'duplicated',
            'precedes' => 'follows',
            'follows' => 'precedes',
            default => $type,
        };

        $sides = [
            [$relation->from, $type, $relation->issue_to_id],
            [$relation->to, $reversedType, $relation->issue_from_id],
        ];

        foreach ($sides as [$issue, $propKey, $otherIssueId]) {
            $journal = Journal::create([
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'notes' => null,
            ]);

            $journal->details()->create([
                'property' => 'relation',
                'prop_key' => $propKey,
                'old_value' => $added ? null : (string) $otherIssueId,
                'new_value' => $added ? (string) $otherIssueId : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $customFieldData  custom_field_id => raw input
     *
     * @throws StaleIssueUpdateException if $expectedLockVersion is given and no longer matches — someone
     *                                   else saved a change since the caller loaded this issue
     */
    public function update(Issue $issue, array $attributes, User $actor, ?string $comment = null, array $customFieldData = [], ?int $expectedLockVersion = null): Issue
    {
        if ($expectedLockVersion !== null && $expectedLockVersion !== $issue->lock_version) {
            throw new StaleIssueUpdateException($issue);
        }

        $original = $issue->only(self::JOURNALED_ATTRIBUTES);
        $originalCustomValues = $this->customFieldSnapshot($issue);

        $issue->fill($attributes);
        $issue->lock_version++;

        $assignedToChanged = $issue->isDirty('assigned_to_id');

        if ($issue->isDirty('status_id')) {
            // Query the target status directly rather than the `status`
            // relation, which may still hold a stale cached instance from
            // before fill() changed status_id.
            $isClosed = IssueStatus::query()->whereKey($issue->status_id)->value('is_closed');
            $issue->closed_on = $isClosed ? now() : null;
            $issue->unsetRelation('status');
        }

        $this->applyStatusDoneRatio($issue);

        $issue->save();

        $issue->setCustomFieldValues($customFieldData);

        if ($assignedToChanged) {
            $this->autoWatch($issue, $issue->assigned_to_id);
        }

        $changes = $this->diff($original, $issue->only(self::JOURNALED_ATTRIBUTES));
        $customFieldChanges = $this->diffCustomFieldSnapshots($originalCustomValues, $this->customFieldSnapshot($issue));

        if ($changes !== [] || $customFieldChanges !== [] || filled($comment)) {
            $journal = Journal::create([
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'notes' => $comment,
            ]);

            foreach ($changes as $field => [$old, $new]) {
                $journal->details()->create([
                    'property' => 'attr',
                    'prop_key' => $field,
                    'old_value' => $old,
                    'new_value' => $new,
                ]);
            }

            foreach ($customFieldChanges as $fieldId => [$old, $new]) {
                $journal->details()->create([
                    'property' => 'cf',
                    'prop_key' => (string) $fieldId,
                    'old_value' => $old,
                    'new_value' => $new,
                ]);
            }
        }

        // Matches the journal-creation condition above: a comment-only
        // update (no attribute or custom field changes) still counts as
        // an issue update for anything listening to this event (webhooks
        // today) — previously it fired for nothing, so a webhook
        // configured for "issue updated" silently never saw plain
        // comments.
        if ($changes !== [] || $customFieldChanges !== [] || filled($comment)) {
            IssueUpdated::dispatch($issue);
        }

        if ($this->isClosingTransition($original, $issue)) {
            $this->closeDuplicates($issue, $actor, $comment);
        }

        $this->recalculateAncestorAttributes($issue->parent_id);

        $oldParentId = $original['parent_id'] ?? null;

        if ($oldParentId !== null && $oldParentId !== $issue->parent_id) {
            $this->recalculateAncestorAttributes($oldParentId);
        }

        return $issue->refresh();
    }

    /**
     * status_id changed, the new status is closed, and the old one
     * wasn't — matches Redmine's Issue#closing?. Reopening (closed to
     * closed, or closed to open) doesn't count.
     *
     * @param  array<string, mixed>  $original
     */
    private function isClosingTransition(array $original, Issue $issue): bool
    {
        $oldStatusId = $original['status_id'] ?? null;

        if ($oldStatusId === $issue->status_id) {
            return false;
        }

        $wasClosed = $oldStatusId !== null && IssueStatus::query()->whereKey($oldStatusId)->value('is_closed');
        $isClosed = IssueStatus::query()->whereKey($issue->status_id)->value('is_closed');

        return (bool) $isClosed && ! $wasClosed;
    }

    /**
     * Closes every issue that duplicates this one, gated by the
     * close_duplicate_issues setting — matches Redmine's Issue#
     * close_duplicates. Re-fetches each duplicate's closed state right
     * before recursing so a mutual-duplicate cycle (A duplicates B,
     * B duplicates A) terminates once the far side is already closed,
     * the same guard Redmine itself relies on.
     */
    private function closeDuplicates(Issue $issue, User $actor, ?string $comment): void
    {
        if (! Setting::get('close_duplicate_issues', true)) {
            return;
        }

        foreach ($issue->duplicates() as $duplicate) {
            $fresh = Issue::query()->find($duplicate->id);

            if ($fresh === null || $fresh->isClosed()) {
                continue;
            }

            $this->update($fresh, ['status_id' => $issue->status_id], $actor, $comment);
        }
    }

    /**
     * Moves an issue to a different project, resetting whatever fields are
     * scoped to the old project and would otherwise reference something
     * that doesn't exist there: category and fixed version (both strictly
     * project-local, so there's no sensible equivalent to carry over),
     * the assignee (only if they're not also a member of the target
     * project), and parent (subtasks are deliberately kept single-project
     * elsewhere in this app, so a stale cross-project parent would just
     * fail re-validation on the next edit). Any of this issue's own
     * children get detached rather than silently left pointing at a
     * parent that moved out from under them.
     */
    public function moveToProject(Issue $issue, Project $targetProject, int $trackerId, User $actor): Issue
    {
        $updates = [
            'project_id' => $targetProject->id,
            'tracker_id' => $trackerId,
            'category_id' => null,
            'fixed_version_id' => null,
            'parent_id' => null,
        ];

        if ($issue->assigned_to_id !== null && ! $targetProject->users()->whereKey($issue->assigned_to_id)->exists()) {
            $updates['assigned_to_id'] = null;
        }

        $moved = $this->update($issue, $updates, $actor, "「{$targetProject->name}」へ移動しました。");

        Issue::query()->where('parent_id', $moved->id)->update(['parent_id' => null]);

        return $moved;
    }

    /**
     * Creates a new issue with the same core attributes and custom field
     * values as $source, in $targetProject. Matches a deliberately scoped
     * subset of Redmine's Issue#copy: category and fixed version are
     * project-local so they're reset (same reasoning as moveToProject),
     * and the assignee is dropped if they aren't a member of the target
     * project. Attachments, subtasks, watchers, and a copied-from relation
     * are NOT duplicated — out of scope for this pass.
     */
    public function copy(Issue $source, Project $targetProject, int $trackerId, User $actor): Issue
    {
        $assignedToId = $source->assigned_to_id;

        if ($assignedToId !== null && ! $targetProject->users()->whereKey($assignedToId)->exists()) {
            $assignedToId = null;
        }

        $customFieldData = $source->relevantCustomFields()
            ->mapWithKeys(fn (CustomField $field) => [$field->id => $this->normalizedCustomFieldValue($source, $field)])
            ->filter(fn (?string $value) => $value !== null)
            ->all();

        return $this->create([
            'project_id' => $targetProject->id,
            'tracker_id' => $trackerId,
            'status_id' => $source->status_id,
            'priority_id' => $source->priority_id,
            'subject' => $source->subject,
            'description' => $source->description,
            'assigned_to_id' => $assignedToId,
            'start_date' => $source->start_date,
            'due_date' => $source->due_date,
            'done_ratio' => $source->done_ratio,
        ], $actor, $customFieldData);
    }

    /**
     * Overrides done_ratio from the issue's (possibly just-changed) status
     * whenever the issue_done_ratio setting is 'issue_status' — matches
     * Redmine's own Issue#update_done_ratio_from_issue_status, called
     * unconditionally on every save rather than only when status_id
     * changes, so an issue re-saved for an unrelated reason still picks
     * up a status's default_done_ratio if it was edited since.
     */
    private function applyStatusDoneRatio(Issue $issue): void
    {
        if (Setting::get('issue_done_ratio', 'issue_field') !== 'issue_status') {
            return;
        }

        $defaultDoneRatio = IssueStatus::query()->whereKey($issue->status_id)->value('default_done_ratio');

        if ($defaultDoneRatio !== null) {
            $issue->done_ratio = $defaultDoneRatio;
        }
    }

    /**
     * Recomputes priority/dates/done_ratio for $parentId and every
     * ancestor above it from their respective children, matching
     * Redmine's Issue#recalculate_attributes_for — walked iteratively up
     * the parent chain rather than Redmine's implicit recursion via
     * save callbacks. Each derived attribute is individually gated by
     * its own Setting (parent_issue_priority/_dates/_done_ratio,
     * default on) and, like Redmine, saved directly without validation,
     * events, or a journal entry — this is a silent bookkeeping
     * recalculation, not a user-authored edit.
     */
    private function recalculateAncestorAttributes(?int $parentId): void
    {
        while ($parentId !== null) {
            $parent = Issue::query()->find($parentId);

            if ($parent === null) {
                return;
            }

            $children = $parent->children()->get();
            $updates = [];

            if (Setting::get('parent_issue_priority', true)) {
                $this->derivePriority($parent, $children, $updates);
            }

            if (Setting::get('parent_issue_dates', true)) {
                $this->deriveDates($children, $updates);
            }

            if (Setting::get('parent_issue_done_ratio', true)) {
                $this->deriveDoneRatio($parent, $children, $updates);
            }

            if ($updates !== []) {
                $parent->forceFill($updates)->save();
            }

            $parentId = $parent->parent_id;
        }
    }

    /**
     * Parent's priority becomes the highest-position priority among its
     * open children; if every child is closed, falls back to the
     * catalog's default priority (left unchanged if there's neither).
     *
     * @param  Collection<int, Issue>  $children
     * @param  array<string, mixed>  $updates
     */
    private function derivePriority(Issue $parent, Collection $children, array &$updates): void
    {
        $openPriorityIds = $children->filter(fn (Issue $child) => ! $child->isClosed())->pluck('priority_id');

        if ($openPriorityIds->isNotEmpty()) {
            $highestPosition = Enumeration::query()->whereIn('id', $openPriorityIds)->max('position');
            $priorityId = Enumeration::query()
                ->ofType(EnumerationType::IssuePriority)
                ->where('position', $highestPosition)
                ->value('id');

            if ($priorityId !== null) {
                $updates['priority_id'] = $priorityId;
            }

            return;
        }

        $defaultPriorityId = Enumeration::query()
            ->ofType(EnumerationType::IssuePriority)
            ->where('is_default', true)
            ->value('id');

        if ($defaultPriorityId !== null) {
            $updates['priority_id'] = $defaultPriorityId;
        }
    }

    /**
     * Parent's start/due dates become the earliest/latest across its
     * children, swapped if that would otherwise put due before start.
     *
     * @param  Collection<int, Issue>  $children
     * @param  array<string, mixed>  $updates
     */
    private function deriveDates(Collection $children, array &$updates): void
    {
        $startDate = $children->pluck('start_date')->filter()->min();
        $dueDate = $children->pluck('due_date')->filter()->max();

        if ($startDate !== null && $dueDate !== null && $dueDate->lt($startDate)) {
            [$startDate, $dueDate] = [$dueDate, $startDate];
        }

        $updates['start_date'] = $startDate;
        $updates['due_date'] = $dueDate;
    }

    /**
     * Parent's done_ratio becomes the average of its children's ratios
     * (100 for a closed child, regardless of its own done_ratio),
     * weighted by each child's total_estimated_hours — a child with no
     * estimate is weighted as the average estimate among children that
     * have one, rather than zero, matching Redmine's Rational-based
     * average exactly. Skipped when the parent's own done_ratio is
     * already status-derived (that setting takes precedence).
     *
     * @param  Collection<int, Issue>  $children
     * @param  array<string, mixed>  $updates
     */
    private function deriveDoneRatio(Issue $parent, Collection $children, array &$updates): void
    {
        if ($children->isEmpty()) {
            return;
        }

        if (Setting::get('issue_done_ratio', 'issue_field') === 'issue_status') {
            $statusDefault = IssueStatus::query()->whereKey($parent->status_id)->value('default_done_ratio');

            if ($statusDefault !== null) {
                return;
            }
        }

        $withEstimates = $children->filter(fn (Issue $child) => $child->totalEstimatedHours() > 0.0);
        $averageEstimate = $withEstimates->isNotEmpty()
            ? $withEstimates->sum(fn (Issue $child) => $child->totalEstimatedHours()) / $withEstimates->count()
            : 1.0;

        $weightedSum = $children->sum(function (Issue $child) use ($averageEstimate) {
            $estimate = $child->totalEstimatedHours() > 0.0 ? $child->totalEstimatedHours() : $averageEstimate;
            $ratio = $child->isClosed() ? 100 : $child->done_ratio;

            return $estimate * $ratio;
        });

        $updates['done_ratio'] = (int) floor($weightedSum / ($averageEstimate * $children->count()));
    }

    /**
     * Matches Redmine's default behavior of auto-watching an issue's
     * author on creation and its assignee whenever assignment changes.
     * firstOrCreate rather than create() since the same user can already
     * be watching (e.g. assigned to the issue they authored).
     */
    private function autoWatch(Issue $issue, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $issue->watchers()->firstOrCreate(['user_id' => $userId]);
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $updated
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(array $original, array $updated): array
    {
        $changes = [];

        foreach (self::JOURNALED_ATTRIBUTES as $field) {
            if ((string) ($original[$field] ?? '') !== (string) ($updated[$field] ?? '')) {
                $changes[$field] = [$original[$field] ?? null, $updated[$field] ?? null];
            }
        }

        return $changes;
    }

    /**
     * A comparable snapshot of this issue's current custom field values,
     * keyed by field id — captured both before and after the update so
     * changes (including from a tracker switch, which can change which
     * fields are relevant) can be diffed into the same journal as core
     * attribute changes.
     *
     * @return array<int, string|null>
     */
    private function customFieldSnapshot(Issue $issue): array
    {
        return $issue->relevantCustomFields()
            ->mapWithKeys(fn (CustomField $field) => [$field->id => $this->normalizedCustomFieldValue($issue, $field)])
            ->all();
    }

    private function normalizedCustomFieldValue(Issue $issue, CustomField $field): ?string
    {
        if ($field->multiple) {
            $values = $issue->customFieldValues
                ->where('custom_field_id', $field->id)
                ->map(fn (CustomFieldValue $value) => (string) $value->value())
                ->sort()
                ->values()
                ->all();

            return $values === [] ? null : implode(',', $values);
        }

        $value = $issue->customValue($field);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @param  array<int, string|null>  $before
     * @param  array<int, string|null>  $after
     * @return array<int, array{0: ?string, 1: ?string}>
     */
    private function diffCustomFieldSnapshots(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $fieldId) {
            $old = $before[$fieldId] ?? null;
            $new = $after[$fieldId] ?? null;

            if ($old !== $new) {
                $changes[$fieldId] = [$old, $new];
            }
        }

        return $changes;
    }
}
