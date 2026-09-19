<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Enums\IssueRelationType;
use App\Enums\IssueTimeEntryDisposition;
use App\Enums\UserStatus;
use App\Enums\VersionStatus;
use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueJournalRecorded;
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
use App\Models\Watcher;
use App\Support\Calendar\WorkingDays;
use App\Support\Mail\MentionParser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
     * Bounds the precedes/follows reschedule cascade — see the
     * $rescheduledIssueIds doc on update() for why this can't be
     * guaranteed cycle-free by relation validation alone.
     */
    private const MAX_RESCHEDULE_CHAIN_LENGTH = 50;

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

        $this->autoWatch($issue, $issue->author_id, 'issue_created');
        $this->autoWatch($issue, $issue->assigned_to_id, 'issue_assigned_to_me');

        $this->recalculateAncestorAttributes($issue->parent_id);

        IssueCreated::dispatch($issue, MentionParser::extractLogins($issue->description));

        return $issue;
    }

    /**
     * Dispatched before the row is actually removed, so listeners (e.g.
     * the webhook payload builder, which serializes the issue's own
     * attributes) see a fully intact model — deleting first would still
     * leave those attributes readable in PHP, but there's no reason to
     * rely on that.
     */
    /**
     * $timeEntries mirrors Redmine's `todo` choice for the issue's logged
     * time. Redmine defaults its confirmation form to deleting the entries;
     * this app has always kept them (detached), so Nullify stays the
     * default for every caller that doesn't ask — a deliberate, data-safe
     * difference. Reassign moves them to $reassignToIssueId, which must be
     * a different issue of the same project (Redmine looks it up through
     * `@project.issues`).
     *
     * @throws ValidationException when reassigning to a missing/foreign issue or the issue itself
     */
    public function delete(
        Issue $issue,
        IssueTimeEntryDisposition $timeEntries = IssueTimeEntryDisposition::Nullify,
        ?int $reassignToIssueId = null,
    ): void {
        $reassignTo = null;

        if ($timeEntries === IssueTimeEntryDisposition::Reassign) {
            $reassignTo = $reassignToIssueId === null
                ? null
                : Issue::query()->where('project_id', $issue->project_id)->find($reassignToIssueId);

            if ($reassignTo === null) {
                throw ValidationException::withMessages(['reassign_to_id' => 'このプロジェクトに存在する課題を指定してください。']);
            }

            if ($reassignTo->is($issue)) {
                throw ValidationException::withMessages(['reassign_to_id' => '削除する課題自身には付け替えできません。']);
            }
        }

        // Dispatched before anything is removed so listeners (the webhook
        // payload builder) still see a fully intact issue.
        IssueDeleted::dispatch($issue);

        DB::transaction(function () use ($issue, $timeEntries, $reassignTo) {
            match ($timeEntries) {
                IssueTimeEntryDisposition::Destroy => $issue->timeEntries()->delete(),
                IssueTimeEntryDisposition::Nullify => $issue->timeEntries()->update(['issue_id' => null]),
                IssueTimeEntryDisposition::Reassign => $issue->timeEntries()->update([
                    'issue_id' => $reassignTo->id,
                    'project_id' => $reassignTo->project_id,
                ]),
            };

            $issue->delete();
        });
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
        $this->journalizeAttachments($issue, [$media], $added, $actor);
    }

    /**
     * Several attachments in one journal — and so one notification — like
     * the single journal Redmine writes for an edit that adds files. Mailed
     * through IssueJournalRecorded (mail only, no webhook); an issue edit's
     * own mail (from update()) is separate, so an edit that also uploads
     * files sends two mails here where Redmine sends one.
     *
     * @param  iterable<int, Media>  $medias
     */
    public function journalizeAttachments(Issue $issue, iterable $medias, bool $added, User $actor): void
    {
        $journal = null;

        foreach ($medias as $media) {
            $journal ??= Journal::create([
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

        if ($journal !== null) {
            IssueJournalRecorded::dispatch($issue, $actor, $journal->load('details'));
        }
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
            'copied_to' => 'copied_from',
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

            IssueJournalRecorded::dispatch($issue, $actor, $journal->load('details'));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $customFieldData  custom_field_id => raw input
     * @param  array<int, int>  $rescheduledIssueIds  internal — issue ids already rescheduled in this
     *                                                cascade, so a precedes/follows chain that loops
     *                                                back on itself (not fully caught by relation-creation
     *                                                validation, see the checklist's "循環/プロジェクト間検証"
     *                                                note) can't recurse forever
     *
     * @throws StaleIssueUpdateException if $expectedLockVersion is given and no longer matches — someone
     *                                   else saved a change since the caller loaded this issue
     */
    public function update(Issue $issue, array $attributes, User $actor, ?string $comment = null, array $customFieldData = [], ?int $expectedLockVersion = null, bool $commentIsPrivate = false, array $rescheduledIssueIds = []): Issue
    {
        if (in_array($issue->id, $rescheduledIssueIds, true) || count($rescheduledIssueIds) >= self::MAX_RESCHEDULE_CHAIN_LENGTH) {
            return $issue;
        }

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
            $this->autoWatch($issue, $issue->assigned_to_id, 'issue_assigned_to_me');
        }

        $changes = $this->diff($original, $issue->only(self::JOURNALED_ATTRIBUTES));
        $customFieldChanges = $this->diffCustomFieldSnapshots($originalCustomValues, $this->customFieldSnapshot($issue));

        $hasDetails = $changes !== [] || $customFieldChanges !== [];
        $detailsJournal = null;

        if ($hasDetails || filled($comment)) {
            $this->autoWatch($issue, $actor->id, 'issue_contributed_to');

            // Matches Redmine's Journal#split_private_notes: a private note
            // combined with attribute changes in the same save is split into
            // two journals, so the public attribute-change record isn't
            // hidden behind view_private_notes just because it happened to
            // ride along with a private comment. Notes-only or details-only
            // saves never need splitting.
            $splitPrivateNotes = $commentIsPrivate && filled($comment) && $hasDetails;

            $detailsJournal = Journal::create([
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'notes' => $splitPrivateNotes ? null : $comment,
                'private_notes' => $splitPrivateNotes ? false : $commentIsPrivate && filled($comment),
            ]);

            foreach ($changes as $field => [$old, $new]) {
                $detailsJournal->details()->create([
                    'property' => 'attr',
                    'prop_key' => $field,
                    'old_value' => $old,
                    'new_value' => $new,
                ]);
            }

            foreach ($customFieldChanges as $fieldId => [$old, $new]) {
                $detailsJournal->details()->create([
                    'property' => 'cf',
                    'prop_key' => (string) $fieldId,
                    'old_value' => $old,
                    'new_value' => $new,
                ]);
            }

            if ($splitPrivateNotes) {
                Journal::create([
                    'issue_id' => $issue->id,
                    'user_id' => $actor->id,
                    'notes' => $comment,
                    'private_notes' => true,
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
            $mentionedLogins = array_unique([
                ...MentionParser::newlyMentionedLogins($original['description'] ?? null, $issue->description),
                ...MentionParser::extractLogins($comment),
            ]);

            IssueUpdated::dispatch($issue, $actor, $detailsJournal, $mentionedLogins);
        }

        if ($this->isClosingTransition($original, $issue)) {
            $this->closeDuplicates($issue, $actor, $comment);
        }

        $this->recalculateAncestorAttributes($issue->parent_id, $actor, $rescheduledIssueIds);

        $oldParentId = $original['parent_id'] ?? null;

        if ($oldParentId !== null && $oldParentId !== $issue->parent_id) {
            $this->recalculateAncestorAttributes($oldParentId);
        }

        if (array_key_exists('start_date', $changes) || array_key_exists('due_date', $changes)) {
            $this->rescheduleSuccessors($issue, $actor, [...$rescheduledIssueIds, $issue->id]);
        }

        return $issue->refresh();
    }

    /**
     * Matches Redmine's IssueRelation#set_issue_to_dates, called right
     * after a precedes/follows relation is created — the successor is
     * rescheduled immediately from the predecessor's *current* dates,
     * rather than waiting for the predecessor to be edited again.
     */
    public function rescheduleFromRelation(IssueRelation $relation, User $actor): void
    {
        if (! in_array($relation->relation_type, [IssueRelationType::Precedes, IssueRelationType::Follows], true)) {
            return;
        }

        $predecessor = $relation->relation_type === IssueRelationType::Precedes ? $relation->from : $relation->to;
        $successor = $relation->relation_type === IssueRelationType::Precedes ? $relation->to : $relation->from;

        if ($predecessor === null || $successor === null) {
            return;
        }

        $this->rescheduleSuccessor($predecessor, $successor, $relation->delay ?? 0, $actor, [$predecessor->id]);
    }

    /**
     * Matches Redmine's Issue#reschedule_following_issues: when a precedes
     * predecessor's dates change, every successor reachable via a
     * precedes/follows relation is pushed forward to start no earlier than
     * the predecessor's due date (or start date, if it has no due date)
     * plus the relation's delay — recursing through the chain via
     * update()'s own $rescheduledIssueIds cascade.
     *
     * Day counts skip the non-working weekdays (WorkingDays). A successor
     * with children whose dates are derived (parent_issue_dates on) is not
     * moved itself: its leaves are, and its dates follow from them — see
     * rescheduleLeaves(). Simplified from Redmine in one way, documented in
     * the parity checklist: successors are only ever pushed later, never
     * pulled earlier when the predecessor finishes sooner.
     *
     * @param  array<int, int>  $rescheduledIssueIds
     */
    private function rescheduleSuccessors(Issue $predecessor, User $actor, array $rescheduledIssueIds): void
    {
        $relations = IssueRelation::query()
            ->where(function (Builder $query) use ($predecessor): void {
                $query->where('issue_from_id', $predecessor->id)->where('relation_type', IssueRelationType::Precedes->value);
            })
            ->orWhere(function (Builder $query) use ($predecessor): void {
                $query->where('issue_to_id', $predecessor->id)->where('relation_type', IssueRelationType::Follows->value);
            })
            ->with(['from', 'to'])
            ->get();

        foreach ($relations as $relation) {
            $successor = $relation->relation_type === IssueRelationType::Precedes ? $relation->to : $relation->from;

            if ($successor === null || in_array($successor->id, $rescheduledIssueIds, true)) {
                continue;
            }

            $this->rescheduleSuccessor($predecessor, $successor, $relation->delay ?? 0, $actor, $rescheduledIssueIds);
        }
    }

    /**
     * @param  array<int, int>  $rescheduledIssueIds
     */
    private function rescheduleSuccessor(Issue $predecessor, Issue $successor, int $delay, User $actor, array $rescheduledIssueIds): void
    {
        $anchor = $predecessor->due_date ?? $predecessor->start_date;

        if ($anchor === null) {
            return;
        }

        // Redmine's successor_soonest_start / reschedule_on: the day count is
        // in working days, and the successor keeps its working duration.
        $soonestStart = WorkingDays::add($anchor, 1 + $delay);

        if ($successor->start_date !== null && $successor->start_date->greaterThanOrEqualTo($soonestStart)) {
            return;
        }

        $duration = $successor->start_date !== null && $successor->due_date !== null
            ? WorkingDays::between($successor->start_date, $successor->due_date)
            : 0;

        $newStart = WorkingDays::nextWorkingDate($soonestStart);

        if (Setting::get('parent_issue_dates', true) && $successor->children()->exists()) {
            $this->rescheduleLeaves($successor, $newStart, $actor, $rescheduledIssueIds);

            return;
        }

        $this->update(
            $successor,
            [
                'start_date' => $newStart->toDateString(),
                'due_date' => WorkingDays::add($newStart, $duration)->toDateString(),
            ],
            $actor,
            rescheduledIssueIds: $rescheduledIssueIds,
        );
    }

    /**
     * Redmine's Issue#reschedule_on! for a parent with derived dates:
     * pushes each leaf below it that starts before $date (or has no start)
     * to start on $date, keeping its working duration; leaves that already
     * start on or after $date stay where they are. The parent's own dates
     * then follow from its leaves (recalculateAncestorAttributes()).
     *
     * @param  array<int, int>  $rescheduledIssueIds
     */
    private function rescheduleLeaves(Issue $parent, CarbonInterface $date, User $actor, array $rescheduledIssueIds): void
    {
        $leaves = Issue::query()
            ->whereIn('id', $parent->descendantIds())
            ->whereDoesntHave('children')
            ->with('project')
            ->orderBy('id')
            ->get();

        foreach ($leaves as $leaf) {
            if ($leaf->start_date !== null && $leaf->start_date->greaterThanOrEqualTo($date)) {
                continue;
            }

            $duration = $leaf->start_date !== null && $leaf->due_date !== null
                ? WorkingDays::between($leaf->start_date, $leaf->due_date)
                : 0;

            $this->update(
                $leaf,
                [
                    'start_date' => $date->toDateString(),
                    'due_date' => WorkingDays::add($date, $duration)->toDateString(),
                ],
                $actor,
                rescheduledIssueIds: $rescheduledIssueIds,
            );
        }
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
     * project. Attachments and watchers are duplicated when the
     * corresponding flag is true (both default true, matching Redmine's
     * bulk-copy form, whose checkboxes are checked by default). Subtasks
     * are duplicated only when $copySubtasks is true (see copySubtasks()).
     * A copied_to relation is created back to $source unless $linkCopy is
     * false, matching Redmine's Issue#after_create_from_copy. Unlike
     * Redmine, this isn't gated by cross_project_issue_relations, since
     * the relation records provenance rather than being a user-authored
     * cross-project link.
     */
    public function copy(Issue $source, Project $targetProject, int $trackerId, User $actor, bool $copyAttachments = true, bool $copyWatchers = true, bool $copySubtasks = false, bool $linkCopy = true): Issue
    {
        $assignedToId = $source->assigned_to_id;

        if ($assignedToId !== null && ! $targetProject->users()->whereKey($assignedToId)->exists()) {
            $assignedToId = null;
        }

        $customFieldData = $source->relevantCustomFields()
            ->mapWithKeys(fn (CustomField $field) => [$field->id => $this->normalizedCustomFieldValue($source, $field)])
            ->filter(fn (?string $value) => $value !== null)
            ->all();

        $copy = $this->create([
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

        $this->finishCopy($source, $copy, $targetProject, $actor, $linkCopy, $copyAttachments, $copyWatchers, $copySubtasks);

        return $copy;
    }

    /**
     * What follows saving a copy: the copied_to relation back to the
     * source (when $linkCopy), attachments, watchers and subtasks. Also the
     * tail of the single-issue copy form, whose new issue is created by the
     * form itself.
     */
    public function finishCopy(Issue $source, Issue $copy, Project $targetProject, User $actor, bool $linkCopy, bool $copyAttachments, bool $copyWatchers, bool $copySubtasks): void
    {
        if ($linkCopy) {
            IssueRelation::create([
                'issue_from_id' => $source->id,
                'issue_to_id' => $copy->id,
                'relation_type' => IssueRelationType::CopiedTo->value,
            ]);
        }

        if ($copyAttachments) {
            foreach ($source->getMedia('attachments') as $media) {
                $media->copy($copy, 'attachments');
            }
        }

        if ($copyWatchers) {
            // firstOrCreate() rather than a bare insert since create()
            // already auto-watched the copy's author/assignee — matches
            // Redmine's own watcher_user_ids= (a set, so no duplicate rows).
            $source->watchers()
                ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
                ->get()
                ->each(fn (Watcher $watcher) => $copy->watchers()->firstOrCreate(['user_id' => $watcher->user_id]));
        }

        if ($copySubtasks) {
            $this->copySubtasks($source, $copy, $targetProject, $actor, $copyAttachments, $copyWatchers);
        }
    }

    /**
     * Redmine's after_create_from_copy subtask pass: walks the source's
     * descendants top-down and copies each one under the copy of its
     * parent (a skipped subtask takes its whole subtree with it, since its
     * children have no copied parent to hang from). Each subtask keeps its
     * own tracker, so one the target project doesn't use is skipped; the
     * version survives only when it is open and reachable from the target
     * project, the category only inside the same project, and the assignee
     * only while active and a member of the target project. Subtasks the
     * actor may not see are never copied (no disclosure of private
     * issues). No copied_to relation is created for subtasks, like Redmine.
     */
    private function copySubtasks(Issue $source, Issue $copy, Project $targetProject, User $actor, bool $copyAttachments, bool $copyWatchers): void
    {
        $source->loadMissing('project');
        $reachableVersionIds = $targetProject->sharedVersions()->pluck('id');
        $trackerIds = $targetProject->trackers()->pluck('trackers.id');
        $copiedIds = [$source->id => $copy];
        $queue = [$source->id];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            $children = Issue::query()
                ->visibleTo($actor, $source->project)
                ->where('parent_id', $parentId)
                ->orderBy('id')
                ->with('fixedVersion')
                ->get();

            foreach ($children as $child) {
                if (! $trackerIds->contains($child->tracker_id)) {
                    continue;
                }

                $assignedToId = $child->assigned_to_id;

                if ($assignedToId !== null && ! $targetProject->users()->whereKey($assignedToId)->where('users.status', UserStatus::Active->value)->exists()) {
                    $assignedToId = null;
                }

                $version = $child->fixedVersion;
                $keepVersion = $version !== null && $version->status === VersionStatus::Open && $reachableVersionIds->contains($version->id);

                $customFieldData = $child->relevantCustomFields()
                    ->mapWithKeys(fn (CustomField $field) => [$field->id => $this->normalizedCustomFieldValue($child, $field)])
                    ->filter(fn (?string $value) => $value !== null)
                    ->all();

                $subtaskCopy = $this->create([
                    'project_id' => $targetProject->id,
                    'tracker_id' => $child->tracker_id,
                    'status_id' => $child->status_id,
                    'priority_id' => $child->priority_id,
                    'subject' => $child->subject,
                    'description' => $child->description,
                    'assigned_to_id' => $assignedToId,
                    'fixed_version_id' => $keepVersion ? $child->fixed_version_id : null,
                    'category_id' => $child->project_id === $targetProject->id ? $child->category_id : null,
                    'start_date' => $child->start_date,
                    'due_date' => $child->due_date,
                    'done_ratio' => $child->done_ratio,
                    'is_private' => $child->is_private,
                    'parent_id' => $copiedIds[$parentId]->id,
                ], $actor, $customFieldData);

                if ($copyAttachments) {
                    foreach ($child->getMedia('attachments') as $media) {
                        $media->copy($subtaskCopy, 'attachments');
                    }
                }

                if ($copyWatchers) {
                    $child->watchers()
                        ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
                        ->get()
                        ->each(fn (Watcher $watcher) => $subtaskCopy->watchers()->firstOrCreate(['user_id' => $watcher->user_id]));
                }

                $copiedIds[$child->id] = $subtaskCopy;
                $queue[] = $child->id;
            }
        }
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
     * recalculation, not a user-authored edit. With an $actor, a parent
     * whose derived dates changed also reschedules its own successors.
     *
     * @param  array<int, int>  $rescheduledIssueIds
     */
    private function recalculateAncestorAttributes(?int $parentId, ?User $actor = null, array $rescheduledIssueIds = []): void
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

                // Like Redmine's saved parent, a derived date change moves
                // the parent's own successors too.
                if ($actor !== null && ($parent->wasChanged('start_date') || $parent->wasChanged('due_date'))) {
                    $this->rescheduleSuccessors($parent, $actor, [...$rescheduledIssueIds, $parent->id]);
                }
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
     * Redmine's auto_watch_on: the user starts watching the issue when the
     * event named by `$event` happens and their personal options list it —
     * `issue_created` (their own new issue), `issue_assigned_to_me`,
     * `issue_contributed_to` (they commented or changed it). The defaults
     * keep this app's long-standing behavior of watching what you create
     * and what is assigned to you. firstOrCreate since the same user can
     * already be watching (e.g. assigned to the issue they authored).
     */
    public function autoWatch(Issue $issue, ?int $userId, string $event): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::query()->find($userId);

        if ($user === null || ! $user->isActive() || ! in_array($event, (array) $user->preference('auto_watch_on'), true)) {
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
