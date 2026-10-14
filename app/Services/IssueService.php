<?php

declare(strict_types=1);

namespace App\Services;

use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\EnumerationType;
use App\Enums\IssueRelationType;
use App\Enums\IssueTimeEntryDisposition;
use App\Events\IssueCreated;
use App\Events\IssueDeleted;
use App\Events\IssueJournalRecorded;
use App\Events\IssueUpdated;
use App\Exceptions\StaleIssueUpdateException;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Watcher;
use App\Rules\IssueRelationTarget;
use App\Support\Calendar\WorkingDays;
use App\Support\Issues\IssueFieldRules;
use App\Support\Mail\MentionParser;
use App\Support\Plugins\BeforeSaveContext;
use App\Support\Plugins\PluginManager;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
    public function __construct(private readonly IssueJournalizer $journalizer) {}

    /**
     * Bounds the precedes/follows reschedule cascade — see the
     * $rescheduledIssueIds doc on update() for why this can't be
     * guaranteed cycle-free by relation validation alone.
     */
    private const MAX_RESCHEDULE_CHAIN_LENGTH = 50;

    /**
     * Leaving `fixed_version_id` out of $attributes lets the project's
     * default version fill it; passing it (even as null) keeps it as given.
     *
     * With $applyFieldRules (input from a user: the form, REST, CSV import,
     * incoming mail) the workflow's read-only fields and the fields the
     * tracker disables are dropped and its required fields must be filled
     * once the defaults are in — Redmine's safe_attributes= and
     * validate_required_fields (IssueFieldRules).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $customFieldData  custom_field_id => raw input
     * @param  array<string, mixed>  $attributesOutsideFieldRules  set as given even with $applyFieldRules
     *                                                             (incoming mail's subject and description,
     *                                                             which Redmine's MailHandler assigns directly)
     *
     * @throws ValidationException when $applyFieldRules and a required field is blank
     */
    public function create(array $attributes, User $author, array $customFieldData = [], bool $applyFieldRules = false, array $attributesOutsideFieldRules = []): Issue
    {
        $issue = new Issue;
        $issue->author_id = $author->id;
        $fieldRules = null;

        if ($applyFieldRules) {
            [$attributes, $customFieldData, $fieldRules] = IssueFieldRules::filterInput($issue, $attributes, $customFieldData, $author);
        }

        $attributes = [...$attributes, ...$attributesOutsideFieldRules];

        $issue->fill($attributes);
        $this->applyCreationDefaults($issue, versionGiven: array_key_exists('fixed_version_id', $attributes));
        $this->applyStatusDoneRatio($issue);
        $fieldRules?->assertRequiredFilled($issue, $customFieldData);
        $this->runBeforeSaveHooks($issue, isNew: true);
        $issue->save();

        // The fields the author may set, not whoever is signed in: a queued
        // import or incoming mail has nobody signed in (A1-28b).
        $issue->setCustomFieldValues($customFieldData, $issue->relevantCustomFields($author));

        $this->autoWatch($issue, $issue->author_id, 'issue_created');
        $this->autoWatch($issue, $issue->assigned_to_id, 'issue_assigned_to_me');

        $this->recalculateAncestorAttributes($issue->parent_id);

        IssueCreated::dispatch($issue, MentionParser::extractLogins($issue->description));

        return $issue;
    }

    /**
     * The defaults every new issue gets, whichever way it is created (form,
     * REST, CSV import, incoming mail, copy): Redmine's Issue#default_assign
     * (before_validation on create) — with no assignee, the category's
     * default assignee, else the project's — and the project's default
     * version, which Issue#project= sets on a new issue without one. Only
     * defaults that are still usable apply (an open shared version; a user
     * or group that can be assigned issues in the project).
     */
    private function applyCreationDefaults(Issue $issue, bool $versionGiven): void
    {
        $project = $issue->project_id !== null ? Project::query()->find($issue->project_id) : null;

        if ($project === null) {
            return;
        }

        if (! $versionGiven && $issue->fixed_version_id === null) {
            $issue->fixed_version_id = $project->usableDefaultVersionId();
        }

        if ($issue->assigned_to_id !== null || $issue->assigned_to_group_id !== null) {
            return;
        }

        $category = $issue->category_id !== null
            ? IssueCategory::query()->where('project_id', $project->id)->find($issue->category_id)
            : null;

        ['assigned_to_id' => $issue->assigned_to_id, 'assigned_to_group_id' => $issue->assigned_to_group_id] = $category?->usableDefaultAssignee()
            ?? ['assigned_to_id' => $project->usableDefaultAssigneeId(), 'assigned_to_group_id' => $project->usableDefaultAssigneeGroupId()];
    }

    /**
     * A15-17: runs every plugin listener subscribed to 'issue.before_save'
     * against $issue — not yet persisted at this point — and raises a
     * ValidationException if any of them vetoed the save (BeforeSaveContext
     * ::fail()), matching Redmine's controller_issues_*_before_save hooks
     * adding to issue.errors and the subsequent save failing validation.
     */
    private function runBeforeSaveHooks(Issue $issue, bool $isNew): void
    {
        $context = new BeforeSaveContext($issue, $isNew);
        app(PluginManager::class)->runBeforeSave('issue.before_save', $context);

        if ($context->failed()) {
            throw ValidationException::withMessages(['issue' => $context->errors()]);
        }
    }

    /**
     * Deletes the issue together with all its subtasks — deleteMany() for
     * a single issue.
     *
     * @throws ValidationException when reassigning to a missing/foreign issue or one being deleted, or detaching time that requires an issue
     */
    public function delete(
        Issue $issue,
        ?IssueTimeEntryDisposition $timeEntries = null,
        ?int $reassignToIssueId = null,
    ): void {
        $this->deleteMany(collect([$issue]), $timeEntries, $reassignToIssueId);
    }

    /**
     * Deletes the issues and every descendant of them, as Redmine's
     * IssuesController#destroy does (`acts_as_nested_set :dependent =>
     * :destroy`): the caller authorizes the selected issues only, and
     * their subtasks go with them whether or not the user could see or
     * delete those on their own. A selection holding both a parent and its
     * child is fine — each issue is deleted once, deepest first.
     *
     * $timeEntries mirrors Redmine's `todo` choice for the time logged on
     * all of those issues (Issue.self_and_descendants). Redmine defaults
     * its confirmation form to deleting the entries; this app has always
     * kept them (detached), so Nullify stays the default for every caller
     * that doesn't ask — a deliberate, data-safe difference — except when
     * `timelog_required_fields` names the issue: then, as in Redmine,
     * Nullify is refused and the default is Destroy (A1-40). Reassign moves
     * them to $reassignToIssueId, which must be an issue of the selection's
     * one project (Redmine looks it up through `@project.issues`) that is
     * not about to be deleted.
     *
     * @param  Collection<int, Issue>  $issues
     *
     * @throws ValidationException when reassigning to a missing/foreign issue or one being deleted, or detaching time that requires an issue
     */
    public function deleteMany(
        Collection $issues,
        ?IssueTimeEntryDisposition $timeEntries = null,
        ?int $reassignToIssueId = null,
    ): void {
        $timeEntries ??= IssueTimeEntryDisposition::defaultForIssueDeletion();
        $doomedIds = Issue::selfAndDescendantIds($issues->pluck('id'));
        $reassignTo = null;

        if (! $timeEntries->isAllowedForIssueDeletion() && TimeEntry::query()->whereIn('issue_id', $doomedIds)->exists()) {
            throw ValidationException::withMessages(['todo' => __('作業時間には課題が必須のため、課題との紐付けを外して残すことはできません。')]);
        }

        if ($timeEntries === IssueTimeEntryDisposition::Reassign) {
            $projectIds = $issues->pluck('project_id')->unique();

            $reassignTo = $reassignToIssueId === null || $projectIds->count() !== 1
                ? null
                : Issue::query()->where('project_id', $projectIds->first())->find($reassignToIssueId);

            if ($reassignTo === null) {
                throw ValidationException::withMessages(['reassign_to_id' => __('このプロジェクトに存在する課題を指定してください。')]);
            }

            if ($doomedIds->contains($reassignTo->id)) {
                throw ValidationException::withMessages(['reassign_to_id' => __('削除する課題(サブタスクを含む)には付け替えできません。')]);
            }
        }

        $position = $doomedIds->flip();
        $doomed = Issue::query()->whereIn('id', $doomedIds)->get()
            ->sortBy(fn (Issue $issue) => $position[$issue->id])
            ->values();

        // Dispatched before anything is removed so listeners (the webhook
        // payload builder) still see fully intact issues.
        foreach ($doomed as $issue) {
            IssueDeleted::dispatch($issue);
        }

        DB::transaction(function () use ($doomed, $doomedIds, $timeEntries, $reassignTo) {
            $entries = TimeEntry::query()->whereIn('issue_id', $doomedIds);

            match ($timeEntries) {
                IssueTimeEntryDisposition::Destroy => $entries->delete(),
                IssueTimeEntryDisposition::Nullify => $entries->update(['issue_id' => null]),
                IssueTimeEntryDisposition::Reassign => $entries->update([
                    'issue_id' => $reassignTo->id,
                    'project_id' => $reassignTo->project_id,
                ]),
            };

            foreach ($doomed as $issue) {
                $issue->delete();
            }

            // Redmine's after_destroy :update_parent_attributes: a parent
            // that survives recomputes its derived attributes — unless it has
            // become a leaf, whose values Redmine no longer derives.
            $doomed->pluck('parent_id')->filter()->unique()
                ->reject(fn (int $parentId) => $doomedIds->contains($parentId))
                ->filter(fn (int $parentId) => Issue::query()->where('parent_id', $parentId)->exists())
                ->each(fn (int $parentId) => $this->recalculateAncestorAttributes($parentId));
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $customFieldData  custom_field_id => raw input
     * @param  array<int, int>  $rescheduledIssueIds  internal — issue ids already rescheduled in this
     *                                                cascade, so a precedes/follows chain that loops
     *                                                back on itself (not fully caught by relation-creation
     *                                                validation, see the checklist's "循環/プロジェクト間検証"
     *                                                note) can't recurse forever
     * @param  bool  $applyFieldRules  input from a user: drop the fields the workflow makes read-only or
     *                                 the tracker disables, and require the workflow's required fields
     *                                 (see create())
     * @param  (Closure(Issue): iterable<int, Media>)|null  $attachFiles  adds the files uploaded with this
     *                                                                    edit once the checks have passed;
     *                                                                    they are journaled in the edit's own
     *                                                                    journal, so one mail covers both
     *                                                                    (Redmine's single journal per save)
     *
     * @throws StaleIssueUpdateException if $expectedLockVersion is given and no longer matches — someone
     *                                   else saved a change since the caller loaded this issue
     * @throws ValidationException when $applyFieldRules and a required field would be left blank
     */
    public function update(Issue $issue, array $attributes, User $actor, ?string $comment = null, array $customFieldData = [], ?int $expectedLockVersion = null, bool $commentIsPrivate = false, array $rescheduledIssueIds = [], bool $applyFieldRules = false, ?Closure $attachFiles = null): Issue
    {
        if (in_array($issue->id, $rescheduledIssueIds, true) || count($rescheduledIssueIds) >= self::MAX_RESCHEDULE_CHAIN_LENGTH) {
            return $issue;
        }

        if ($expectedLockVersion !== null && $expectedLockVersion !== $issue->lock_version) {
            throw new StaleIssueUpdateException($issue);
        }

        $fieldRules = null;

        if ($applyFieldRules) {
            [$attributes, $customFieldData, $fieldRules] = IssueFieldRules::filterInput($issue, $attributes, $customFieldData, $actor);
        }

        // A project change is Redmine's Issue#project=: the fields scoped to
        // the old project are adjusted (whatever the caller sets explicitly
        // wins), and after the save the subtasks, time entries and relations
        // follow (afterProjectChange()).
        $oldProjectId = $issue->project_id;
        $targetProject = isset($attributes['project_id']) && (int) $attributes['project_id'] !== $oldProjectId
            ? Project::query()->findOrFail((int) $attributes['project_id'])
            : null;

        if ($targetProject !== null) {
            $movingIssueIds = $this->assertSubtasksCanMove($issue, $targetProject);
            $attributes = [...$this->projectChangeAttributes($issue, $targetProject, $actor), ...$attributes];
        }

        $fieldRules?->assertRequiredFilled(
            (clone $issue)->fill($attributes),
            $customFieldData + $issue->customFieldFormValues($fieldRules->customFields()),
        );

        $original = $this->journalizer->attributeSnapshot($issue);
        $originalCustomValues = $this->journalizer->customFieldSnapshot($issue, $actor);

        $issue->fill($attributes);
        $issue->lock_version++;

        if ($targetProject !== null) {
            $issue->setRelation('project', $targetProject);

            if ($issue->relationLoaded('tracker')) {
                $issue->load('tracker');
            }
        }

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
        $this->runBeforeSaveHooks($issue, isNew: false);

        $issue->save();

        if ($targetProject !== null) {
            $this->afterProjectChange($issue, $targetProject, $movingIssueIds, $actor);
        }

        if ($targetProject !== null || $issue->wasChanged('tracker_id')) {
            $this->deleteUnavailableCustomFieldValues($issue);
        }

        $issue->setCustomFieldValues($customFieldData, $issue->relevantCustomFields($actor));

        if ($assignedToChanged) {
            $this->autoWatch($issue, $issue->assigned_to_id, 'issue_assigned_to_me');
        }

        $changes = $this->journalizer->attributeChanges($original, $this->journalizer->attributeSnapshot($issue));
        $customFieldChanges = $this->journalizer->customFieldChanges($originalCustomValues, $this->journalizer->customFieldSnapshot($issue, $actor));
        $attachedMedia = $attachFiles === null ? [] : [...$attachFiles($issue)];

        // Files alone keep their own mail-only journal (no issue.updated
        // webhook), as IssueJournalizer::journalizeAttachments() records them.
        $attachmentsOnly = $changes === [] && $customFieldChanges === [] && ! filled($comment) && $attachedMedia !== [];

        if ($attachmentsOnly) {
            $this->journalizer->journalizeAttachments($issue, $attachedMedia, added: true, actor: $actor);
        }

        $hasDetails = $changes !== [] || $customFieldChanges !== [] || $attachedMedia !== [];
        $detailsJournal = null;
        $splitPrivateJournal = null;

        if (! $attachmentsOnly && ($hasDetails || filled($comment))) {
            $this->autoWatch($issue, $actor->id, 'issue_contributed_to');
            [$detailsJournal, $splitPrivateJournal] = $this->journalizer->recordEdit($issue, $actor, $comment, $commentIsPrivate, $changes, $customFieldChanges, $attachedMedia);
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

        // The private half of a split save is a journal of its own; Redmine mails it too (to those
        // who may read private notes), so it gets the mail-only event rather than no mail at all.
        if ($splitPrivateJournal !== null) {
            IssueJournalRecorded::dispatch($issue, $actor, $splitPrivateJournal->load('details'));
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
     * Creates a relation from $from to the issue $toIssueId with the same
     * checks as the issue page's "add relation" (IssueRelationTarget, as
     * $actor sees the target, plus existence, self and duplicate checks),
     * then journals it on both issues and reschedules a precedes/follows
     * successor, as the page does. The caller authorizes the action; the
     * CSV import uses it for its relation columns (A1-28c).
     *
     * @throws ValidationException
     */
    public function addRelation(Issue $from, int $toIssueId, string $relationType, ?int $delay, User $actor): IssueRelation
    {
        Validator::make(
            ['issue_to_id' => $toIssueId, 'relation_type' => $relationType, 'delay' => $delay],
            [
                'issue_to_id' => [
                    'required', 'integer', Rule::exists('issues', 'id'),
                    Rule::notIn([$from->id]),
                    Rule::unique('issue_relations', 'issue_to_id')
                        ->where('issue_from_id', $from->id)
                        ->where('relation_type', $relationType),
                    new IssueRelationTarget($from, $relationType, $actor),
                ],
                // 'copied_to' is here only for the CSV importer's
                // relation_copied_to/relation_copied_from columns
                // (A15-15) — the manual "add relation" form and the REST
                // API create a copied_to relation through their own
                // direct IssueRelation::create() path, not through this
                // method.
                'relation_type' => ['required', Rule::in(['relates', 'blocks', 'duplicates', 'precedes', 'follows', 'copied_to'])],
                'delay' => ['nullable', 'integer', 'min:0'],
            ],
        )->validate();

        $relation = IssueRelation::create([
            'issue_from_id' => $from->id,
            'issue_to_id' => $toIssueId,
            'relation_type' => $relationType,
            'delay' => in_array($relationType, ['precedes', 'follows'], true) ? $delay : null,
        ]);

        $this->journalizer->journalizeRelation($relation, added: true, actor: $actor);
        $this->rescheduleFromRelation($relation, $actor);

        return $relation;
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
     * Moves an issue to a different project with the given tracker — an
     * update() of its project (see projectChangeAttributes() and
     * afterProjectChange() for what follows the issue), journaled with a
     * note naming the target.
     *
     * @throws ValidationException when a subtask's tracker is not used in the target project
     */
    public function moveToProject(Issue $issue, Project $targetProject, int $trackerId, User $actor): Issue
    {
        return $this->update($issue, ['project_id' => $targetProject->id, 'tracker_id' => $trackerId], $actor, "「{$targetProject->name}」へ移動しました。");
    }

    /**
     * The fields scoped to the old project, as Redmine's Issue#project=
     * adjusts them on an existing issue moved to $targetProject: a tracker
     * the target does not use gives way to the first one $actor may add
     * there (Redmine takes the target's first tracker; the A1-27 limits
     * are kept here), the category is swapped for the target's category of
     * the same name (none if it has no such category), the fixed version
     * stays while the target shares it — open or not — and is cleared
     * otherwise (a moved issue does not get the target's default version,
     * which Redmine only gives new issues), and the parent is cleared
     * (subtasks stay within one project here — Redmine's
     * valid_parent_project? without cross-project subtasks). The assignee
     * (user or group) stays even when not assignable in the target:
     * Redmine clears it only for a new issue (a copy), an existing issue's
     * assignee still being valid.
     *
     * @return array<string, mixed>
     */
    private function projectChangeAttributes(Issue $issue, Project $targetProject, ?User $actor, bool $keepTracker = false): array
    {
        $attributes = [
            'category_id' => $this->carriedCategoryId($issue, $targetProject),
            'fixed_version_id' => $issue->fixed_version_id !== null && $targetProject->sharedVersions()->contains('id', $issue->fixed_version_id)
                ? $issue->fixed_version_id
                : null,
        ];

        if (! $keepTracker) {
            $attributes['parent_id'] = null;

            if (! $targetProject->trackers()->whereKey($issue->tracker_id)->exists()) {
                $attributes['tracker_id'] = Issue::allowedTargetTrackers($targetProject, $actor)->first()?->id ?? $issue->tracker_id;
            }
        }

        return $attributes;
    }

    /**
     * The subtasks that move with $issue — Redmine's after_project_change
     * moves the children that were in the same project (and theirs in turn)
     * keeping their trackers, and a child whose tracker the target does not
     * use fails the whole move.
     *
     * @return Collection<int, Issue>
     *
     * @throws ValidationException
     */
    private function assertSubtasksCanMove(Issue $issue, Project $targetProject): Collection
    {
        $subtasks = collect();
        $level = collect([$issue->id]);

        while ($level->isNotEmpty()) {
            $children = Issue::query()->whereIn('parent_id', $level)->where('project_id', $issue->project_id)->with('tracker')->orderBy('id')->get();
            $subtasks = $subtasks->merge($children);
            $level = $children->pluck('id');
        }

        $targetTrackerIds = $targetProject->trackers()->pluck('trackers.id');

        foreach ($subtasks as $subtask) {
            if (! $targetTrackerIds->contains($subtask->tracker_id)) {
                throw ValidationException::withMessages([
                    'project_id' => __('サブタスク #:id を移動できません: トラッカー「:tracker」は移動先のプロジェクトで使用されていません。', ['id' => $subtask->id, 'tracker' => $subtask->tracker?->name]),
                ]);
            }
        }

        return $subtasks;
    }

    /**
     * Redmine's reassign_custom_field_values on a project or tracker change:
     * the values of custom fields the issue's new project and tracker don't
     * use are deleted (with an attachment field's file), not kept for a
     * move back. Not journaled — Redmine's journal only compares the fields
     * the issue has after the change.
     */
    private function deleteUnavailableCustomFieldValues(Issue $issue): void
    {
        $availableIds = $issue->availableCustomFields()->pluck('id')->all();

        $issue->customFieldValues()->whereNotIn('custom_field_id', $availableIds)->delete();

        $issue->getMedia(AttachmentFormat::COLLECTION)
            ->reject(fn (Media $media): bool => in_array((int) $media->getCustomProperty('custom_field_id'), $availableIds, true))
            ->each(fn (Media $media) => $media->delete());

        $issue->unsetRelation('customFieldValues');
    }

    /**
     * Redmine's Issue#after_project_change for $issue and the subtasks that
     * moved with it: their time entries follow them to the target, their
     * relations are removed unless cross-project relations are allowed, and
     * the subtasks take the target's category/version like the issue
     * (projectChangeAttributes(), trackers kept) — saved without a journal,
     * as Redmine saves them.
     *
     * @param  Collection<int, Issue>  $subtasks
     */
    private function afterProjectChange(Issue $issue, Project $targetProject, Collection $subtasks, User $actor): void
    {
        foreach ($subtasks as $subtask) {
            $subtask->fill([...$this->projectChangeAttributes($subtask, $targetProject, $actor, keepTracker: true), 'project_id' => $targetProject->id]);
            $subtask->save();
            $this->deleteUnavailableCustomFieldValues($subtask->setRelation('project', $targetProject));
        }

        $movedIds = $subtasks->pluck('id')->push($issue->id);

        TimeEntry::query()->whereIn('issue_id', $movedIds)->update(['project_id' => $targetProject->id]);

        if (! Setting::get('cross_project_issue_relations', false)) {
            IssueRelation::query()
                ->where(fn (Builder $query) => $query->whereIn('issue_from_id', $movedIds)->orWhereIn('issue_to_id', $movedIds))
                ->delete();
        }
    }

    /**
     * The category $issue takes into $targetProject (Redmine's
     * Issue#project=): its own within the same project, the target's
     * category of the same name in another one, else none.
     */
    public function carriedCategoryId(Issue $issue, Project $targetProject): ?int
    {
        $category = $issue->loadMissing('category')->category;

        return match (true) {
            $category === null => null,
            $issue->project_id === $targetProject->id => $category->id,
            default => IssueCategory::query()->where('project_id', $targetProject->id)->where('name', $category->name)->value('id'),
        };
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
     * A group assignee is never auto-watched — Redmine's Journal#add_watcher
     * only watches a User assignee; its members are notified as assignees
     * through NotificationRecipients instead, without watcher rows.
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
}
