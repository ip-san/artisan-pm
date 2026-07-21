<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\User;

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
        'tracker_id', 'status_id', 'priority_id', 'subject', 'description',
        'assigned_to_id', 'fixed_version_id', 'parent_id', 'start_date',
        'due_date', 'done_ratio',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $author): Issue
    {
        $issue = new Issue;
        $issue->fill($attributes);
        $issue->author_id = $author->id;
        $issue->save();

        return $issue;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Issue $issue, array $attributes, User $actor, ?string $comment = null): Issue
    {
        $original = $issue->only(self::JOURNALED_ATTRIBUTES);

        $issue->fill($attributes);

        if ($issue->isDirty('status_id')) {
            // Query the target status directly rather than the `status`
            // relation, which may still hold a stale cached instance from
            // before fill() changed status_id.
            $isClosed = IssueStatus::query()->whereKey($issue->status_id)->value('is_closed');
            $issue->closed_on = $isClosed ? now() : null;
            $issue->unsetRelation('status');
        }

        $issue->save();

        $changes = $this->diff($original, $issue->only(self::JOURNALED_ATTRIBUTES));

        if ($changes !== [] || filled($comment)) {
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
        }

        return $issue->refresh();
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
}
