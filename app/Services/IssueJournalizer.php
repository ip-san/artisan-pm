<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\IssueJournalRecorded;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Writes an issue's journals, the history Redmine keeps of every change:
 * the attribute, custom field and attachment details of an edit (see
 * IssueService::update(), which takes the before/after snapshots here and
 * diffs them), and the journal of an attachment or relation added or
 * removed on its own.
 */
final class IssueJournalizer
{
    /**
     * Attributes tracked in the journal when they change.
     *
     * @var array<string>
     */
    public const array JOURNALED_ATTRIBUTES = [
        'project_id', 'tracker_id', 'status_id', 'priority_id', 'category_id', 'subject',
        'description', 'assigned_to_id', 'assigned_to_group_id', 'fixed_version_id', 'parent_id',
        'start_date', 'due_date', 'done_ratio', 'estimated_hours', 'is_private',
    ];

    /**
     * The journaled attributes as they stand — taken before and after a
     * change and compared with attributeChanges().
     *
     * @return array<string, mixed>
     */
    public function attributeSnapshot(Issue $issue): array
    {
        return $issue->only(self::JOURNALED_ATTRIBUTES);
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $updated
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function attributeChanges(array $original, array $updated): array
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
    public function customFieldSnapshot(Issue $issue, User $actor): array
    {
        // setCustomFieldValues() drops the loaded relation; reload it
        // explicitly rather than lazily (lazy loading is disabled).
        $issue->loadMissing('customFieldValues');

        return $issue->relevantCustomFields($actor)
            ->mapWithKeys(fn (CustomField $field) => [$field->id => $this->normalizedCustomFieldValue($issue, $field)])
            ->all();
    }

    /**
     * @param  array<int, string|null>  $before
     * @param  array<int, string|null>  $after
     * @return array<int, array{0: ?string, 1: ?string}>
     */
    public function customFieldChanges(array $before, array $after): array
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

    /**
     * The value of $field on $issue as journals compare it: blank is null,
     * and a multiple field's values are sorted and joined.
     */
    public function normalizedCustomFieldValue(Issue $issue, CustomField $field): ?string
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
     * The journal of an edit: its notes and one detail per changed
     * attribute, custom field and newly attached file. Matches Redmine's
     * Journal#split_private_notes: a private note combined with attribute
     * changes in the same save is split into two journals, so the public
     * attribute-change record isn't hidden behind view_private_notes just
     * because it happened to ride along with a private comment. Notes-only
     * or details-only saves never need splitting.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<int, array{0: ?string, 1: ?string}>  $customFieldChanges
     * @param  array<int, Media>  $attachedMedia
     * @return array{0: Journal, 1: ?Journal} the details journal, and the private notes split off it
     */
    public function recordEdit(Issue $issue, User $actor, ?string $comment, bool $commentIsPrivate, array $changes, array $customFieldChanges, array $attachedMedia): array
    {
        $hasDetails = $changes !== [] || $customFieldChanges !== [] || $attachedMedia !== [];
        $splitPrivateNotes = $commentIsPrivate && filled($comment) && $hasDetails;
        $splitPrivateJournal = null;

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

        foreach ($attachedMedia as $media) {
            $detailsJournal->details()->create([
                'property' => 'attachment',
                'prop_key' => (string) $media->id,
                'old_value' => null,
                'new_value' => $media->file_name,
            ]);
        }

        if ($splitPrivateNotes) {
            $splitPrivateJournal = Journal::create([
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'notes' => $comment,
                'private_notes' => true,
            ]);
        }

        return [$detailsJournal, $splitPrivateJournal];
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
     * through IssueJournalRecorded (mail only, no webhook). Files uploaded
     * together with an edit go through update()'s $attachFiles instead, so
     * they share the edit's journal and mail.
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
        $sides = [
            [$relation->from, $relation->relation_type->journalKey(), $relation->issue_to_id],
            [$relation->to, $relation->relation_type->journalKey(false), $relation->issue_from_id],
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
}
