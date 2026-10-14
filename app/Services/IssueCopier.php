<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IssueRelationType;
use App\Enums\UserStatus;
use App\Enums\VersionStatus;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Project;
use App\Models\User;
use App\Models\Watcher;
use App\Support\Issues\AssigneeChoice;
use Illuminate\Support\Collection;

/**
 * Copies issues — Redmine's Issue#copy and after_create_from_copy, for the
 * bulk copy and the single-issue copy form: the copy itself (through
 * IssueService::create()), the copied_to relation back to the source, and
 * its attachments, watchers and subtasks.
 */
final class IssueCopier
{
    public function __construct(
        private readonly IssueService $issues,
        private readonly IssueJournalizer $journalizer,
    ) {}

    /**
     * Creates a new issue with the same core attributes and custom field
     * values as $source, in $targetProject. Matches a deliberately scoped
     * subset of Redmine's Issue#copy: the version and category follow
     * Redmine's Issue#project= (see carriedVersionAndCategory()), and the
     * assignee is dropped if they aren't a member of the target project. Attachments and watchers are duplicated when the
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
        [$assignedToId, $assignedToGroupId] = $this->copiedAssignee($source, $targetProject, $actor);

        $copy = $this->issues->create([
            ...$this->carriedVersionAndCategory($source, $targetProject, $targetProject->sharedVersions()->pluck('id')),
            'project_id' => $targetProject->id,
            'tracker_id' => $trackerId,
            'status_id' => $source->status_id,
            'priority_id' => $source->priority_id,
            'subject' => $source->subject,
            'description' => $source->description,
            'assigned_to_id' => $assignedToId,
            'assigned_to_group_id' => $assignedToGroupId,
            'start_date' => $source->start_date,
            'due_date' => $source->due_date,
            'done_ratio' => $source->done_ratio,
        ], $actor, $this->copiedCustomFieldData($source));

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

        $this->copyAttachmentsAndWatchers($source, $copy, $copyAttachments, $copyWatchers);

        if ($copySubtasks) {
            $this->copySubtasks($source, $copy, $targetProject, $actor, $copyAttachments, $copyWatchers);
        }
    }

    /**
     * Redmine's after_create_from_copy subtask pass: walks the source's
     * descendants top-down and copies each one under the copy of its
     * parent (a skipped subtask takes its whole subtree with it, since its
     * children have no copied parent to hang from). Each subtask keeps its
     * own tracker, so one the target project doesn't use, or the actor may
     * not create issues with, is skipped; an open version is carried over
     * as for the copied issue itself (carriedVersionAndCategory()), any
     * other is cleared, the category is kept or matched by name, and the assignee
     * only while active and a member of the target project. Subtasks the
     * actor may not see are never copied (no disclosure of private
     * issues). No copied_to relation is created for subtasks, like Redmine.
     */
    private function copySubtasks(Issue $source, Issue $copy, Project $targetProject, User $actor, bool $copyAttachments, bool $copyWatchers): void
    {
        $source->loadMissing('project');
        $reachableVersionIds = $targetProject->sharedVersions()->pluck('id');
        // Redmine's validate_permissions on a copy: only trackers the actor
        // may create issues with (allowed_target_trackers).
        $trackerIds = Issue::allowedTargetTrackers($targetProject, $actor)->pluck('id');
        $copiedIds = [$source->id => $copy];
        $queue = [$source->id];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            $children = Issue::query()
                ->visibleTo($actor, $source->project)
                ->where('parent_id', $parentId)
                ->orderBy('id')
                ->with(['fixedVersion', 'category'])
                ->get();

            foreach ($children as $child) {
                if (! $trackerIds->contains($child->tracker_id)) {
                    continue;
                }

                [$assignedToId, $assignedToGroupId] = $this->copiedAssignee($child, $targetProject, $actor);

                $carried = $this->carriedVersionAndCategory($child, $targetProject, $reachableVersionIds);

                // Redmine's after_create_from_copy clears the version of a
                // subtask whose own version isn't open, default included.
                if ($child->fixedVersion?->status !== VersionStatus::Open) {
                    $carried['fixed_version_id'] = null;
                }

                $subtaskCopy = $this->issues->create([
                    'project_id' => $targetProject->id,
                    'tracker_id' => $child->tracker_id,
                    'status_id' => $child->status_id,
                    'priority_id' => $child->priority_id,
                    'subject' => $child->subject,
                    'description' => $child->description,
                    'assigned_to_id' => $assignedToId,
                    'assigned_to_group_id' => $assignedToGroupId,
                    ...$carried,
                    'start_date' => $child->start_date,
                    'due_date' => $child->due_date,
                    'done_ratio' => $child->done_ratio,
                    'is_private' => $child->is_private,
                    'parent_id' => $copiedIds[$parentId]->id,
                ], $actor, $this->copiedCustomFieldData($child));

                $this->copyAttachmentsAndWatchers($child, $subtaskCopy, $copyAttachments, $copyWatchers);

                $copiedIds[$child->id] = $subtaskCopy;
                $queue[] = $child->id;
            }
        }
    }

    /**
     * The version and category a copy of $issue takes into $targetProject,
     * as Redmine's Issue#project= decides them: the version stays while it
     * is open and shared with the target ($reachableVersionIds); otherwise
     * the key is left out so IssueService::create() fills in the target's default version
     * — except for a copy within the same project of an issue without one,
     * which Redmine leaves empty. The category stays within the same
     * project and is matched by name in another one.
     *
     * @param  Collection<int, int>  $reachableVersionIds
     * @return array{fixed_version_id?: ?int, category_id: ?int}
     */
    private function carriedVersionAndCategory(Issue $issue, Project $targetProject, Collection $reachableVersionIds): array
    {
        $issue->loadMissing(['fixedVersion', 'category']);
        $sameProject = $issue->project_id === $targetProject->id;
        $version = $issue->fixedVersion;
        $attributes = [];

        if ($version !== null && $version->status === VersionStatus::Open && $reachableVersionIds->contains($version->id)) {
            $attributes['fixed_version_id'] = $version->id;
        } elseif ($sameProject && $version === null) {
            $attributes['fixed_version_id'] = null;
        }

        $attributes['category_id'] = $this->issues->carriedCategoryId($issue, $targetProject);

        return $attributes;
    }

    /**
     * The assignee a copy of $source keeps in $targetProject — Redmine's Issue#project= for a new
     * record: kept only if the copy's assignable_users include them (members holding an
     * assignable role, active, groups only while issue_group_assignment is on, and the copy's
     * author, who is always assignable), otherwise cleared.
     *
     * @return array{0: int|null, 1: int|null} user id, group id
     */
    private function copiedAssignee(Issue $source, Project $targetProject, User $author): array
    {
        $userId = $source->assigned_to_id;

        if ($userId !== null) {
            $assignable = $targetProject->assignableUsers()->where('status', UserStatus::Active)->contains('id', $userId)
                || ($userId === $author->id && $author->status === UserStatus::Active);

            $userId = $assignable ? $userId : null;
        }

        $groupId = $source->assigned_to_group_id !== null && AssigneeChoice::allowsGroup($targetProject, $source->assigned_to_group_id)
            ? $source->assigned_to_group_id
            : null;

        return [$userId, $groupId];
    }

    /**
     * $issue's custom field values as create() takes them.
     *
     * @return array<int, string>
     */
    private function copiedCustomFieldData(Issue $issue): array
    {
        return $issue->relevantCustomFields()
            ->mapWithKeys(fn (CustomField $field) => [$field->id => $this->journalizer->normalizedCustomFieldValue($issue, $field)])
            ->filter(fn (?string $value) => $value !== null)
            ->all();
    }

    /**
     * Duplicates $source's attachments and its active watchers onto $copy.
     * firstOrCreate() rather than a bare insert since IssueService::create()
     * already auto-watched the copy's author/assignee — matches Redmine's
     * own watcher_user_ids= (a set, so no duplicate rows).
     */
    private function copyAttachmentsAndWatchers(Issue $source, Issue $copy, bool $copyAttachments, bool $copyWatchers): void
    {
        if ($copyAttachments) {
            foreach ($source->getMedia('attachments') as $media) {
                $media->copy($copy, 'attachments');
            }
        }

        if ($copyWatchers) {
            $source->watchers()
                ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
                ->get()
                ->each(fn (Watcher $watcher) => $copy->watchers()->firstOrCreate(['user_id' => $watcher->user_id]));
        }
    }
}
