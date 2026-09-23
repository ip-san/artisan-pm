<?php

use App\Enums\CustomFieldFormat;
use App\Enums\IssueTimeEntryDisposition;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use App\Services\ReactionService;
use App\Support\Issues\RelatedIssueColumns;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * How each relation type reads from the "from" side vs. the "to" side
     * of the row — e.g. issue A "blocks" issue B, but viewed from B the
     * same row should read "blocked by". `relates` has no directional
     * language, and `precedes`/`follows` are already distinct storable
     * types (picked directly by the user), so only blocks/duplicates/
     * copied_to need a computed reverse label. copied_to is the only
     * direction ever stored (see IssueRelationType) — viewed from the
     * source issue it reads "コピー先" (copy destination), viewed from
     * the copy it reads "コピー元" (copy source).
     *
     * @return array<string, array{from: string, to: string}>
     */
    private function relationLabels(): array
    {
        return [
            'relates' => ['from' => __('関連'), 'to' => __('関連')],
            'blocks' => ['from' => __('ブロックする'), 'to' => __('ブロックされている')],
            'duplicates' => ['from' => __('重複する'), 'to' => __('重複されている')],
            'precedes' => ['from' => __('先行'), 'to' => __('先行')],
            'follows' => ['from' => __('後続'), 'to' => __('後続')],
            'copied_to' => ['from' => __('コピー先'), 'to' => __('コピー元')],
        ];
    }

    /**
     * Maps a relation journal's prop_key (including the reversed names
     * Redmine writes on the receiving end, e.g. "blocked") back to the
     * [type, side] pair used to index relationLabels() above.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array RELATION_JOURNAL_KEYS = [
        'relates' => ['relates', 'from'],
        'blocks' => ['blocks', 'from'],
        'blocked' => ['blocks', 'to'],
        'duplicates' => ['duplicates', 'from'],
        'duplicated' => ['duplicates', 'to'],
        'precedes' => ['precedes', 'from'],
        'follows' => ['follows', 'from'],
        'copied_to' => ['copied_to', 'from'],
        'copied_from' => ['copied_to', 'to'],
    ];

    /**
     * Everything a rendered Journal row needs: its author and detail rows,
     * plus the reaction data <x-reaction-button> reads — the count itself
     * and the walk back to the project that ReactionService::canReact()
     * performs (journal -> issue -> project).
     *
     * Livewire re-hydrates $this->issue from the snapshot on every request
     * after the first, which drops the relations mount() eager-loaded. So
     * visibleJournals() re-applies this list itself instead of trusting
     * mount(), or every follow-up request (a reaction toggle, a new
     * comment) would lazy-load these per journal.
     *
     * @var list<string>
     */
    private const array JOURNAL_RELATIONS = [
        'journals.user',
        'journals.updatedBy',
        'journals.details',
        'journals.reactions',
        'journals.issue.project',
    ];

    public Project $project;

    public Issue $issue;

    public string $comment = '';

    public bool $commentIsPrivate = false;

    public ?int $relatedIssueId = null;

    public string $relatedSearch = '';

    public string $relationType = 'relates';

    public ?int $relationDelay = null;

    public ?int $newWatcherId = null;

    public string $watcherSearch = '';

    public ?int $moveToProjectId = null;

    public ?int $moveToTrackerId = null;

    /** @var array<int, string> attachment media id => description input value */
    public array $attachmentDescriptions = [];

    public function mount(Project $project, Issue $issue): void
    {
        $this->authorize('view', $issue);

        $this->project = $project;
        $this->issue = $issue->load(['tracker', 'status', 'priority', 'category', 'author', 'assignedTo', 'fixedVersion', ...self::JOURNAL_RELATIONS, 'reactions', 'customFieldValues', 'timeEntries.user', 'timeEntries.activity', 'relationsFrom.to.tracker', 'relationsFrom.to.project', 'relationsTo.from.tracker', 'relationsTo.from.project', 'parent.tracker', 'parent.status', 'children.tracker', 'children.status', 'watchers.user', 'changesets.repository.project']);

        foreach ($this->issue->attachments() as $media) {
            $this->attachmentDescriptions[$media->id] = (string) $media->getCustomProperty('description', '');
        }
    }

    /**
     * The description and every Journal comment share the same Markdown
     * dialect as Wiki pages (#123 links, [[Page]] links, inline images
     * resolved against this issue's own attachments) — previously neither
     * was rendered as Markdown at all, just shown as raw text.
     */
    #[Computed]
    public function renderedDescription(): string
    {
        return app(WikiMarkdownRenderer::class)->render((string) $this->issue->description, $this->project, $this->issue->attachments());
    }

    public function renderedNotes(Journal $journal): string
    {
        return app(WikiMarkdownRenderer::class)->render((string) $journal->notes, $this->project, $this->issue->attachments());
    }

    /**
     * @return Collection<int, array{relation: IssueRelation, other: Issue, label: string}>
     */
    #[Computed]
    public function relations(): Collection
    {
        $with = RelatedIssueColumns::relationsFor(array_keys($this->relatedColumns));

        (new \Illuminate\Database\Eloquent\Collection(
            $this->issue->relationsFrom->pluck('to')->concat($this->issue->relationsTo->pluck('from'))->all()
        ))->loadMissing($with);

        $labels = $this->relationLabels();

        // Redmine keeps only relations whose other issue the viewer may see.
        $visibleIds = Issue::filterVisible(
            $this->issue->relationsFrom->pluck('to')->concat($this->issue->relationsTo->pluck('from'))->filter()->unique('id')->values(),
            auth()->user(),
        )->pluck('id')->flip();

        $from = $this->issue->relationsFrom->filter(fn (IssueRelation $relation) => $visibleIds->has($relation->issue_to_id))->map(fn (IssueRelation $relation) => [
            'relation' => $relation,
            'other' => $relation->to,
            'label' => $labels[$relation->relation_type->value]['from'],
        ]);

        $to = $this->issue->relationsTo->filter(fn (IssueRelation $relation) => $visibleIds->has($relation->issue_from_id))->map(fn (IssueRelation $relation) => [
            'relation' => $relation,
            'other' => $relation->from,
            'label' => $labels[$relation->relation_type->value]['to'],
        ]);

        return $from->concat($to)->sortBy(fn (array $entry) => $entry['relation']->id);
    }

    /**
     * The extra columns of the subtask and related-issue tables (Redmine's
     * related_issues_default_columns).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function relatedColumns(): array
    {
        return RelatedIssueColumns::selected();
    }

    /**
     * Direct subtasks with what the selected columns need already loaded.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Issue>
     */
    #[Computed]
    public function subtasks(): \Illuminate\Database\Eloquent\Collection
    {
        // Redmine lists `issue.descendants.visible`: a subtask the viewer
        // may not see is left out.
        return new \Illuminate\Database\Eloquent\Collection(Issue::filterVisible($this->issue->children, auth()->user())->all())
            ->loadMissing(RelatedIssueColumns::relationsFor(array_keys($this->relatedColumns)));
    }

    /**
     * The parent, when the viewer may see it — Redmine shows only visible
     * ancestors above the subject.
     */
    #[Computed]
    public function visibleParent(): ?Issue
    {
        $parent = $this->issue->parent;

        return $parent !== null && $parent->isVisibleTo(auth()->user()) ? $parent : null;
    }

    /**
     * Issues that could be related to this one, found by id or subject; other
     * projects too when cross-project relations are allowed.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Issue>
     */
    #[Computed]
    public function relatedSuggestions(): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Support\Issues\IssueSuggestions::search(auth()->user(), $this->relatedSearch, $this->issue->project, allowOtherProjects: true, excludeIssueId: $this->issue->id);
    }

    public function pickRelated(int $issueId): void
    {
        $this->relatedIssueId = $issueId;
        $this->reset('relatedSearch');
        unset($this->relatedSuggestions);
    }

    public function addRelation(): void
    {
        $this->authorize('manageRelations', $this->issue);

        $data = $this->validate([
            'relatedIssueId' => [
                'required', 'integer', Rule::exists('issues', 'id'),
                Rule::notIn([$this->issue->id]),
                Rule::unique('issue_relations', 'issue_to_id')
                    ->where('issue_from_id', $this->issue->id)
                    ->where('relation_type', $this->relationType),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $other = Issue::find($value);

                    if ($other === null) {
                        return;
                    }

                    // Checked first, so the messages below never describe an
                    // issue the user may not see.
                    if (! $other->isVisibleTo(auth()->user())) {
                        $fail(__('課題が見つかりません。'));

                        return;
                    }

                    if ($other->project_id !== $this->issue->project_id && ! Setting::get('cross_project_issue_relations', false)) {
                        $fail(__('プロジェクトをまたぐ関連付けは許可されていません。'));

                        return;
                    }

                    if ($this->issue->descendantIds()->contains($other->id) || $other->descendantIds()->contains($this->issue->id)) {
                        $fail(__('親子・祖先/子孫関係にある課題同士は関連付けできません。'));

                        return;
                    }

                    if ($this->relationType === 'relates') {
                        $reverseExists = IssueRelation::query()
                            ->where('issue_from_id', $other->id)
                            ->where('issue_to_id', $this->issue->id)
                            ->where('relation_type', 'relates')
                            ->exists();

                        if ($reverseExists) {
                            $fail(__('この関連は既に登録されています。'));
                        }
                    }

                    if ($this->relationType === 'blocks') {
                        $reverseBlocks = IssueRelation::query()
                            ->where('issue_from_id', $other->id)
                            ->where('issue_to_id', $this->issue->id)
                            ->where('relation_type', 'blocks')
                            ->exists();

                        if ($reverseBlocks) {
                            $fail(__('循環したブロック関係は作成できません。'));
                        }
                    }

                    if ($this->relationType === 'precedes' && IssueRelation::wouldCreateCycle($this->issue, $other)) {
                        $fail(__('先行関係が循環しています。'));
                    }

                    if ($this->relationType === 'follows' && IssueRelation::wouldCreateCycle($other, $this->issue)) {
                        $fail(__('先行関係が循環しています。'));
                    }
                },
            ],
            // copied_to is deliberately excluded from Rule::enum() here —
            // it's system-generated only (see IssueService::copy()),
            // matching Redmine's own "add relation" form, which never
            // offers it as a manually selectable type either.
            'relationType' => ['required', Rule::in(['relates', 'blocks', 'duplicates', 'precedes', 'follows'])],
            'relationDelay' => ['nullable', 'integer', 'min:0'],
        ]);

        $otherIssue = Issue::findOrFail($data['relatedIssueId']);
        $this->authorize('view', $otherIssue);

        // delay is only meaningful for precedes/follows — matches
        // Redmine's IssueRelation, which clears it for every other type.
        $isSequential = in_array($data['relationType'], ['precedes', 'follows'], true);

        $relation = IssueRelation::create([
            'issue_from_id' => $this->issue->id,
            'issue_to_id' => $otherIssue->id,
            'relation_type' => $data['relationType'],
            'delay' => $isSequential ? $data['relationDelay'] : null,
        ]);

        app(IssueService::class)->journalizeRelation($relation, added: true, actor: auth()->user());
        app(IssueService::class)->rescheduleFromRelation($relation, auth()->user());

        $this->reset('relatedIssueId', 'relationDelay');
        $this->issue->refresh();
        $this->reloadRelations();
        $this->reloadJournals();
    }

    public function deleteRelation(int $relationId): void
    {
        $this->authorize('manageRelations', $this->issue);

        $relation = IssueRelation::query()
            ->where(fn ($q) => $q->where('issue_from_id', $this->issue->id)->orWhere('issue_to_id', $this->issue->id))
            ->findOrFail($relationId);

        $relation->delete();
        app(IssueService::class)->journalizeRelation($relation, added: false, actor: auth()->user());

        $this->reloadRelations();
        $this->reloadJournals();
    }

    private function reloadRelations(): void
    {
        $this->issue->load(['relationsFrom.to.tracker', 'relationsFrom.to.project', 'relationsTo.from.tracker', 'relationsTo.from.project']);
        unset($this->relations);
    }

    private function reloadJournals(): void
    {
        $this->issue->load(self::JOURNAL_RELATIONS);
        unset($this->visibleJournals);
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function customFieldNames(): Collection
    {
        return CustomField::query()->pluck('name', 'id');
    }

    public function journalDetailLabel(JournalDetail $detail): string
    {
        if ($detail->property !== 'cf') {
            return $detail->prop_key;
        }

        return $this->customFieldNames[(int) $detail->prop_key] ?? $detail->prop_key;
    }

    /**
     * @return Collection<int, int>
     */
    #[Computed]
    public function longTextCustomFieldIds(): Collection
    {
        return CustomField::query()->where('field_format', CustomFieldFormat::Text)->pluck('id');
    }

    /**
     * Whether this journal detail is a long-text custom field change —
     * the "cf" counterpart to the description diff, matching Redmine's
     * own change_as_diff? being limited to the "text" field format.
     */
    public function isLongTextCustomFieldDetail(JournalDetail $detail): bool
    {
        return $detail->property === 'cf' && $this->longTextCustomFieldIds->contains((int) $detail->prop_key);
    }

    /**
     * A relation journal's prop_key is the type as seen from this issue,
     * including the reversed names Redmine uses on the receiving end
     * (blocked/duplicated) that never appear in IssueRelationType itself.
     */
    public function relationJournalLabel(string $propKey): string
    {
        [$type, $side] = self::RELATION_JOURNAL_KEYS[$propKey] ?? [null, null];

        return $type !== null ? $this->relationLabels()[$type][$side] : $propKey;
    }

    /**
     * @return Collection<int, array{field: CustomField, value: mixed}>
     */
    #[Computed]
    public function customFieldDisplayValues(): Collection
    {
        return $this->issue->relevantCustomFields()->map(fn (CustomField $field) => [
            'field' => $field,
            'value' => $field->multiple
                ? $this->issue->customFieldValues->where('custom_field_id', $field->id)->map(fn ($v) => $v->value())->join(', ')
                : $this->issue->customValue($field),
        ]);
    }

    public function quote(int $journalId): void
    {
        $this->authorize('addNotes', $this->issue);

        $journal = $this->visibleJournals->firstWhere('id', $journalId);

        if ($journal === null || blank($journal->notes)) {
            return;
        }

        $quoted = collect(explode("\n", $journal->notes))->map(fn (string $line) => "> {$line}")->implode("\n");

        $this->comment = "{$journal->user->displayName()} wrote:\n{$quoted}\n\n";
    }

    /**
     * Which history tab is open: `history` (everything), `notes`,
     * `properties` or `changesets`. Empty means the user's own default.
     */
    #[Url(as: 'tab')]
    public string $historyTab = '';

    public ?int $editingJournalId = null;

    public string $editingJournalNotes = '';

    public bool $editingJournalPrivate = false;

    public function startEditingJournal(int $journalId): void
    {
        $journal = $this->issue->journals->firstWhere('id', $journalId);

        if ($journal === null) {
            return;
        }

        $this->authorize('update', $journal);

        $this->editingJournalId = $journalId;
        $this->editingJournalNotes = (string) $journal->notes;
        $this->editingJournalPrivate = $journal->private_notes;
    }

    public function cancelEditingJournal(): void
    {
        $this->reset('editingJournalId', 'editingJournalNotes', 'editingJournalPrivate');
    }

    /**
     * Blank notes are allowed on purpose — matching Redmine, which has no
     * dedicated "delete comment" action at all (JournalsController only
     * routes :edit/:update). Clearing the text is how a comment is
     * removed; any attribute changes recorded in the same journal stay
     * visible (Journal#isEmpty() only hides the row when both notes and
     * details are empty).
     */
    public function saveJournalEdit(): void
    {
        $journal = Journal::query()->findOrFail($this->editingJournalId);
        $this->authorize('update', $journal);

        $data = $this->validate(['editingJournalNotes' => ['nullable', 'string']]);

        $attributes = ['notes' => $data['editingJournalNotes'] ?? '', 'updated_by_id' => auth()->id()];

        // Redmine's `private_notes` safe attribute: only someone holding
        // set_notes_private may flip it; for anyone else it is left alone.
        if (auth()->user()->can('setNotesPrivate', $this->issue)) {
            $attributes['private_notes'] = $this->editingJournalPrivate;
        }

        $journal->update($attributes);

        $this->reset('editingJournalId', 'editingJournalNotes', 'editingJournalPrivate');
        $this->reloadJournals();
    }

    public function addComment(): void
    {
        $this->authorize('addNotes', $this->issue);

        $data = $this->validate(['comment' => ['required', 'string']]);

        app(IssueService::class)->autoWatch($this->issue, auth()->id(), 'issue_contributed_to');

        Journal::create([
            'issue_id' => $this->issue->id,
            'user_id' => auth()->id(),
            'notes' => $data['comment'],
            // Trusts the checkbox's own gate, not the client: even if the
            // hidden input were tampered with, only a user who actually
            // holds set_notes_private can flip this to true.
            'private_notes' => $this->commentIsPrivate && auth()->user()->can('setNotesPrivate', $this->issue),
        ]);

        $this->reset('comment', 'commentIsPrivate');
        $this->reloadJournals();
    }

    /**
     * @return Collection<int, Journal>
     */
    /**
     * The tabs offered for this issue and viewer, in order: every issue has
     * the three journal views, related revisions appear when there are any
     * and the viewer may see changesets.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function historyTabs(): array
    {
        $tabs = UserPreferences::historyTabLabels();

        if ($this->issue->changesets->isNotEmpty() && auth()->user()?->can('viewAny', [Repository::class, $this->project])) {
            $tabs['changesets'] = __('チェンジセット');
        }

        return $tabs;
    }

    #[Computed]
    public function activeHistoryTab(): string
    {
        $tabs = $this->historyTabs;

        if (array_key_exists($this->historyTab, $tabs)) {
            return $this->historyTab;
        }

        $default = (string) auth()->user()?->preference('history_default_tab');

        return array_key_exists($default, $tabs) ? $default : 'history';
    }

    public function setHistoryTab(string $tab): void
    {
        $this->historyTab = array_key_exists($tab, $this->historyTabs) ? $tab : '';
    }

    #[Computed]
    public function visibleJournals(): Collection
    {
        $this->issue->loadMissing(self::JOURNAL_RELATIONS);

        $user = auth()->user();

        // Redmine's comments_sorting: the reader's choice of oldest or newest first.
        $ordered = $user?->preference('comments_sorting') === 'desc' ? $this->issue->journals->reverse()->values() : $this->issue->journals;

        if ($user !== null && $user->can('viewPrivateNotes', $this->issue)) {
            return $ordered;
        }

        // A user can always see their own private notes, even without
        // view_private_notes — matching Redmine's Journal#visible?. A guest
        // (null $user) never has a "own" notes, so they only ever see
        // non-private journals.
        return $ordered
            ->filter(fn (Journal $journal) => ! $journal->private_notes || ($user !== null && $journal->user_id === $user->id))
            ->values();
    }

    public function toggleWatch(): void
    {
        $this->authorize('watch', $this->issue);

        $existing = $this->issue->watchers()->where('user_id', auth()->id())->first();

        if ($existing) {
            $existing->delete();
        } else {
            $this->issue->watchers()->create(['user_id' => auth()->id()]);
        }

        $this->issue->unsetRelation('watchers');
    }

    public function toggleReaction(string $type, int $id): void
    {
        $reactable = match ($type) {
            'issue' => Issue::query()->findOrFail($id),
            'journal' => Journal::query()->findOrFail($id),
            default => abort(404),
        };

        $user = auth()->user();

        if (! app(ReactionService::class)->canReact($user, $reactable)) {
            abort(403);
        }

        app(ReactionService::class)->toggle($reactable, $user);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function watcherCandidates(): Collection
    {
        $watchingIds = $this->issue->watchers->pluck('user_id');
        $search = mb_strtolower(trim($this->watcherSearch));

        // A name/email search over the members not yet watching, ten at a
        // time (the picker is an autocomplete, not a full list).
        return $this->issue->project->users
            ->reject(fn (User $user) => $watchingIds->contains($user->id))
            ->filter(fn (User $user) => $search === '' || str_contains(mb_strtolower($user->name.' '.$user->email), $search))
            ->take(10)
            ->values();
    }

    public function pickWatcher(int $userId): void
    {
        $this->newWatcherId = $userId;
        $this->addWatcher();
    }

    public function addWatcher(): void
    {
        $this->authorize('addWatchers', $this->issue);

        // Scoped to the issue's OWN project, not $this->project: the two are
        // the same for any URL the app generates, but authorization here runs
        // against $this->issue while the candidate list/validation would
        // otherwise run against whatever project the URL named — so a
        // mismatched URL could attach a non-member as a watcher. The routes
        // scope their bindings now, making that unreachable; this keeps the
        // component correct on its own terms rather than by routing luck.
        $data = $this->validate([
            'newWatcherId' => ['required', Rule::exists('members', 'user_id')->where('project_id', $this->issue->project_id)],
        ]);

        $this->issue->watchers()->firstOrCreate(['user_id' => $data['newWatcherId']]);

        $this->reset('newWatcherId');
        $this->issue->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    public function removeWatcher(int $userId): void
    {
        $this->authorize('deleteWatchers', $this->issue);

        $this->issue->watchers()->where('user_id', $userId)->delete();

        $this->issue->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    public function deleteAttachment(int $mediaId): void
    {
        $this->authorize('update', $this->issue);

        $media = $this->issue->attachments()->firstWhere('id', $mediaId);

        if ($media === null) {
            return;
        }

        $media->delete();
        app(IssueService::class)->journalizeAttachment($this->issue, $media, added: false, actor: auth()->user());
        $this->reloadJournals();
    }

    /**
     * Matches Redmine's Attachment#description — free text edited from
     * wherever the attachment is listed, not just at upload time. Read
     * from the bound attachmentDescriptions array (keyed by media id,
     * pre-filled in mount()) rather than taking the value as a parameter,
     * since a wire:click can't read a sibling input's live value directly.
     */
    public function updateAttachmentDescription(int $mediaId): void
    {
        $this->authorize('update', $this->issue);

        $media = $this->issue->attachments()->firstWhere('id', $mediaId);
        abort_if($media === null, 404);

        $description = trim((string) ($this->attachmentDescriptions[$mediaId] ?? ''));
        $media->setCustomProperty('description', $description !== '' ? $description : null);
        $media->save();
    }

    public bool $confirmingDelete = false;

    public string $timeEntryTodo = 'nullify';

    public string $reassignToId = '';

    /**
     * Hours logged against this issue and the subtasks deleted with it —
     * when there are any, the delete confirmation asks what to do with them
     * (Redmine's issues/destroy.html.erb over Issue.self_and_descendants).
     */
    #[Computed]
    public function loggedHoursForDeletion(): float
    {
        return (float) TimeEntry::query()
            ->whereIn('issue_id', Issue::selfAndDescendantIds([$this->issue->id]))
            ->sum('hours');
    }

    /**
     * Subtasks deleted along with this issue (Redmine's
     * issues_destroy_confirmation_message).
     */
    #[Computed]
    public function deletionDescendantCount(): int
    {
        return Issue::descendantCountForDeletion(collect([$this->issue]));
    }

    public function deleteIssue(): void
    {
        $this->authorize('delete', $this->issue);

        $disposition = IssueTimeEntryDisposition::Nullify;

        if ($this->loggedHoursForDeletion > 0) {
            $requested = IssueTimeEntryDisposition::tryFrom($this->timeEntryTodo);

            // A value the panel never offers can only be a tampered request:
            // refuse it rather than guessing (nothing has been deleted yet).
            if ($requested === null) {
                $this->addError('timeEntryTodo', __('作業時間の扱いが不正です。'));

                return;
            }

            $disposition = $requested;
        }

        app(IssueService::class)->delete(
            $this->issue,
            $disposition,
            $this->reassignToId !== '' ? (int) $this->reassignToId : null,
        );

        $this->redirect(route('issues.index', $this->project), navigate: true);
    }

    /**
     * Other projects the user could move this issue into — must hold
     * add_issues there, matching Redmine's own requirement that moving
     * somewhere still lets you create issues in the destination.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function moveTargetProjects(): Collection
    {
        return Project::query()
            ->where('id', '!=', $this->project->id)
            ->get()
            ->filter(fn (Project $candidate) => auth()->user()?->can('create', [Issue::class, $candidate]))
            ->values();
    }

    /**
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function moveTargetTrackers(): Collection
    {
        if ($this->moveToProjectId === null) {
            return collect();
        }

        $target = $this->moveTargetProjects->firstWhere('id', $this->moveToProjectId);

        return $target !== null ? Issue::allowedTargetTrackers($target, auth()->user()) : collect();
    }

    public function moveIssue(): void
    {
        $this->authorize('move', $this->issue);

        $data = $this->validate([
            'moveToProjectId' => ['required', Rule::in($this->moveTargetProjects->pluck('id')->all())],
            'moveToTrackerId' => ['required', Rule::in($this->moveTargetTrackers->pluck('id')->all())],
        ]);

        $targetProject = Project::findOrFail($data['moveToProjectId']);

        $issue = app(IssueService::class)->moveToProject($this->issue, $targetProject, $data['moveToTrackerId'], auth()->user());

        $this->redirect(route('issues.show', [$targetProject, $issue]), navigate: true);
    }
}; ?>

<div class="max-w-3xl">
    <div class="flex items-start justify-between mb-4">
        <div>
            @if ($this->visibleParent)
                <p class="text-xs text-neutral-500 mb-1">
                    <span class="text-neutral-400">{{ __('親課題:') }}</span>
                    <a href="{{ route('issues.show', [$this->visibleParent->project, $this->visibleParent]) }}" class="text-brand-bold hover:underline">
                        {{ $this->visibleParent->tracker->name }} #{{ $this->visibleParent->id }} — {{ $this->visibleParent->subject }}
                    </a>
                </p>
            @endif
            <p class="text-sm text-neutral-500">{{ $issue->tracker->name }} #{{ $issue->id }}</p>
            <h1 class="text-xl font-semibold text-neutral-900">
                {{ $issue->subject }}
                @if ($issue->is_private)
                    <span class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 align-middle text-xs font-normal text-neutral-600">{{ __('非公開') }}</span>
                @endif
            </h1>
        </div>
        <div class="flex gap-2">
            @can('watch', $issue)
                <button wire:click="toggleWatch" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    {{ $issue->isWatchedBy(auth()->user()) ? __('ウォッチ解除') : __('ウォッチ') }}
                </button>
            @endcan
            @can('create', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.create', $project) }}?issue_id={{ $issue->id }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    {{ __('工数を記録') }}
                </a>
            @endcan
            <a href="{{ route('issues.pdf', [$project, $issue]) }}"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                PDF
            </a>
            @can('create', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.create', $project) }}?copy_from={{ $issue->id }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    {{ __('コピー') }}
                </a>
            @endcan
            @can('update', $issue)
                <a href="{{ route('issues.edit', [$project, $issue]) }}"
                    class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                    {{ __('編集') }}
                </a>
            @endcan
            @can('delete', $issue)
                @if ($this->loggedHoursForDeletion > 0)
                    <button wire:click="$set('confirmingDelete', true)"
                        class="rounded-md border border-danger-subtle px-3 py-2 text-sm font-medium text-danger-bolder hover:bg-danger-subtlest">
                        {{ __('削除') }}
                    </button>
                @else
                    <button wire:click="deleteIssue" wire:confirm="{{ __('この課題を削除しますか?この操作は取り消せません。') }}{{ $this->deletionDescendantCount > 0 ? ' '.__(':count件のサブタスクも削除されます。', ['count' => $this->deletionDescendantCount]) : '' }}"
                        class="rounded-md border border-danger-subtle px-3 py-2 text-sm font-medium text-danger-bolder hover:bg-danger-subtlest">
                        {{ __('削除') }}
                    </button>
                @endif
            @endcan
        </div>
    </div>

    @if ($confirmingDelete && $this->loggedHoursForDeletion > 0)
        @can('delete', $issue)
            <form wire:submit="deleteIssue" class="mb-6 space-y-3 rounded-md border border-danger-subtler bg-danger-subtlest p-4">
                @if ($this->deletionDescendantCount > 0)
                    <p class="text-sm font-medium text-danger-boldest">{{ __(':count件のサブタスクも削除されます。', ['count' => $this->deletionDescendantCount]) }}</p>
                    <p class="text-sm font-medium text-danger-boldest">
                        {{ __('この課題とサブタスクには :hours 時間の作業時間が記録されています。削除する課題の作業時間をどうしますか?', ['hours' => rtrim(rtrim(number_format($this->loggedHoursForDeletion, 2), '0'), '.')]) }}
                    </p>
                @else
                    <p class="text-sm font-medium text-danger-boldest">
                        {{ __('この課題には :hours 時間の作業時間が記録されています。削除する課題の作業時間をどうしますか?', ['hours' => rtrim(rtrim(number_format($this->loggedHoursForDeletion, 2), '0'), '.')]) }}
                    </p>
                @endif
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="radio" wire:model.live="timeEntryTodo" value="nullify">
                    {{ __('課題との紐付けを外してプロジェクトに残す') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="radio" wire:model.live="timeEntryTodo" value="destroy">
                    {{ __('作業時間も一緒に削除する') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="radio" wire:model.live="timeEntryTodo" value="reassign">
                    {{ __('このプロジェクトの別の課題へ付け替える:') }} #
                    <input type="number" min="1" wire:model="reassignToId" wire:focus="$set('timeEntryTodo', 'reassign')"
                        class="w-24 rounded-md border-neutral-300 text-sm">
                </label>
                @error('reassign_to_id') <p class="text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <div class="flex gap-2">
                    <button type="submit" class="rounded-md bg-danger-bolder px-3 py-2 text-sm font-medium text-white hover:bg-danger-subtle">
                        {{ __('削除する') }}
                    </button>
                    <button type="button" wire:click="$set('confirmingDelete', false)"
                        class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                        {{ __('キャンセル') }}
                    </button>
                </div>
            </form>
        @endcan
    @endif

    @can('move', $issue)
        @if ($this->moveTargetProjects->isNotEmpty())
            <form wire:submit="moveIssue" class="mb-6 flex flex-wrap items-end gap-2 rounded-md border border-neutral-200 bg-white p-4">
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('別のプロジェクトへ移動') }}</label>
                    <select wire:model.live="moveToProjectId" class="mt-1 block rounded-md border-neutral-300 text-sm">
                        <option value="">{{ __('選択してください') }}</option>
                        @foreach ($this->moveTargetProjects as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                    @error('moveToProjectId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                @if ($moveToProjectId)
                    <div>
                        <label class="block text-xs font-medium text-neutral-700">{{ __('移動後のトラッカー') }}</label>
                        <select wire:model="moveToTrackerId" class="mt-1 block rounded-md border-neutral-300 text-sm">
                            <option value="">{{ __('選択してください') }}</option>
                            @foreach ($this->moveTargetTrackers as $candidateTracker)
                                <option value="{{ $candidateTracker->id }}">{{ $candidateTracker->name }}</option>
                            @endforeach
                        </select>
                        @error('moveToTrackerId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:confirm="{{ __('移動するとカテゴリ・対象バージョン・親課題はリセットされます。よろしいですか?') }}"
                        class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                        {{ __('移動') }}
                    </button>
                @endif
            </form>
        @endif
    @endcan

    <div class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-neutral-200 bg-white p-4 text-sm mb-6">
        <div><span class="text-neutral-500">{{ __('ステータス:') }}</span> {{ $issue->status->name }}</div>
        <div><span class="text-neutral-500">{{ __('優先度:') }}</span> {{ $issue->priority->name }}</div>
        <div><span class="text-neutral-500">{{ __('カテゴリ:') }}</span> {{ $issue->category?->name ?? __('なし') }}</div>
        <div><span class="text-neutral-500">{{ __('作成者:') }}</span> <x-avatar :user="$issue->author" :size="18" /> {{ $issue->author->displayName() }}</div>
        <div><span class="text-neutral-500">{{ __('担当者:') }}</span> <x-avatar :user="$issue->assignedTo" :size="18" /> {{ $issue->assignedTo?->name ?? __('未割当') }}</div>
        <div><span class="text-neutral-500">{{ __('対象バージョン:') }}</span> {{ $issue->fixedVersion?->name ?? __('なし') }}</div>
        <div><span class="text-neutral-500">{{ __('進捗率:') }}</span> {{ $issue->done_ratio }}%</div>
        <div><span class="text-neutral-500">{{ __('開始日:') }}</span> {{ $issue->start_date?->toDateString() ?? '-' }}</div>
        <div><span class="text-neutral-500">{{ __('期日:') }}</span> {{ $issue->due_date?->toDateString() ?? '-' }}</div>
        <div>
            <span class="text-neutral-500">{{ __('予定工数:') }}</span>
            {{ $issue->estimated_hours !== null ? __(':hours 時間', ['hours' => \App\Support\Format\Hours::format((float) $issue->estimated_hours)]) : '-' }}
            @if (! $issue->isLeaf() && $issue->totalEstimatedHours() > 0)
                <span class="text-neutral-400">{{ __('(合計: :hours 時間)', ['hours' => \App\Support\Format\Hours::format($issue->totalEstimatedHours())]) }}</span>
            @endif
        </div>
        @if ($issue->estimated_hours !== null)
            <div>
                <span class="text-neutral-500">{{ __('残り工数(予定):') }}</span>
                {{ __(':hours 時間', ['hours' => \App\Support\Format\Hours::format($issue->estimatedRemainingHours())]) }}
            </div>
        @endif
    </div>

    @if ($issue->description)
        <div class="prose prose-sm max-w-none mb-6 rounded-md border border-neutral-200 bg-white p-4">
            {!! $this->renderedDescription !!}
        </div>
    @endif

    <div class="mb-6">
        <x-reaction-button :reactable="$issue" type="issue" />
    </div>

    @if ($this->customFieldDisplayValues->isNotEmpty())
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-neutral-200 bg-white p-4 text-sm mb-6">
            @foreach ($this->customFieldDisplayValues as $entry)
                <div>
                    <span class="text-neutral-500">{{ $entry['field']->name }}:</span>
                    <x-custom-field-value :field="$entry['field']" :value="$entry['value']" />
                </div>
            @endforeach
        </div>
    @endif

    @if (auth()->user()?->can('viewWatchers', $issue) && ($issue->watchers->isNotEmpty() || auth()->user()?->can('addWatchers', $issue)))
        <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('ウォッチャー (:count)', ['count' => $issue->watchers->count()]) }}</h2>
        <ul class="mb-3 flex flex-wrap gap-2">
            @foreach ($issue->watchers as $watcher)
                <li class="flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 text-xs text-neutral-700">
                    {{ $watcher->user->displayName() }}
                    @can('deleteWatchers', $issue)
                        <button wire:click="removeWatcher({{ $watcher->user_id }})" class="text-neutral-400 hover:text-danger-bolder" title="{{ __('ウォッチャーから削除') }}">×</button>
                    @endcan
                </li>
            @endforeach
        </ul>

        @can('addWatchers', $issue)
            @if ($watcherSearch !== '' || $this->watcherCandidates->isNotEmpty())
                <div class="mb-6  relative" data-watcher-search>
                    <input type="text" wire:model.live.debounce.250ms="watcherSearch" placeholder="{{ __('ウォッチャーを追加(名前・メールで検索)...') }}"
                        class="block w-72 rounded-md border-neutral-300 shadow-sm text-sm">
                    <ul class="mt-1 max-h-48 w-72 overflow-y-auto rounded-md border border-neutral-200 bg-white text-sm shadow-sm">
                        @foreach ($this->watcherCandidates as $candidate)
                            <li wire:key="watcher-candidate-{{ $candidate->id }}">
                                <button type="button" wire:click="pickWatcher({{ $candidate->id }})" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">
                                    {{ $candidate->name }} <span class="text-xs text-neutral-400">{{ $candidate->email }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @error('newWatcherId') <p class="-mt-4 mb-6 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            @else
                <div class="mb-6"></div>
            @endif
        @endcan
    @endif

    @if ($this->subtasks->isNotEmpty())
        <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('サブタスク') }}</h2>
        <div class="mb-6 overflow-x-auto rounded-md border border-neutral-200 bg-white">
            <table class="min-w-full text-sm" data-related-issues="subtasks">
                @if (\App\Support\Issues\RelatedIssueColumns::showHeaders())
                    <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('題名') }}</th>
                            @foreach ($this->relatedColumns as $label)
                                <th class="px-3 py-2 font-medium">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                @endif
                <tbody class="divide-y divide-neutral-100">
                    @foreach ($this->subtasks as $child)
                        <tr wire:key="subtask-{{ $child->id }}">
                            <td class="px-3 py-2">
                                <a href="{{ route('issues.show', [$project, $child]) }}" class="text-brand-bold hover:underline">
                                    {{ $child->tracker->name }} #{{ $child->id }} — {{ $child->subject }}
                                </a>
                            </td>
                            @foreach ($this->relatedColumns as $key => $label)
                                <td class="px-3 py-2 text-neutral-500">{{ \App\Support\Issues\RelatedIssueColumns::value($child, $key) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php $attachments = $issue->attachments(); @endphp
    @if ($attachments->isNotEmpty())
        <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('添付ファイル') }}<x-attachment-bulk-links :container="$issue" :count="$attachments->count()" /></h2>
        <ul class="mb-6 space-y-1">
            @foreach ($attachments as $media)
                <li class="py-1 text-sm" wire:key="issue-attachment-{{ $media->id }}">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <x-attachment-thumbnail :media="$media" />
                            <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-brand-bold hover:underline">
                                {{ $media->file_name }}
                            </a>
                        </span>
                        <span class="text-neutral-500">{{ $media->human_readable_size }}</span>
                        <x-download-count :media="$media" />
<x-attachment-preview-link :media="$media" />
                        @can('update', $issue)
                            <button wire:click="deleteAttachment({{ $media->id }})" wire:confirm="{{ __('この添付ファイルを削除しますか?') }}"
                                class="text-danger-bolder hover:underline">{{ __('削除') }}</button>
                        @endcan
                    </div>
                    @can('update', $issue)
                        <div class="mt-1 flex items-center gap-2">
                            <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="{{ __('説明(任意)') }}"
                                class="block w-full rounded-md border-neutral-300 text-xs shadow-sm">
                            <button wire:click="updateAttachmentDescription({{ $media->id }})"
                                class="shrink-0 text-xs text-brand-bold hover:underline">{{ __('保存') }}</button>
                        </div>
                    @elseif ($media->getCustomProperty('description'))
                        <p class="mt-1 text-xs text-neutral-500">{{ $media->getCustomProperty('description') }}</p>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->relations->isNotEmpty() || auth()->user()?->can('manageRelations', $issue))
        <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('関連課題') }}</h2>
        @if ($this->relations->isNotEmpty())
            <div class="mb-4 overflow-x-auto rounded-md border border-neutral-200 bg-white">
                <table class="min-w-full text-sm" data-related-issues="relations">
                    @if (\App\Support\Issues\RelatedIssueColumns::showHeaders())
                        <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ __('関連') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('題名') }}</th>
                                @foreach ($this->relatedColumns as $label)
                                    <th class="px-3 py-2 font-medium">{{ $label }}</th>
                                @endforeach
                                <th></th>
                            </tr>
                        </thead>
                    @endif
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($this->relations as $entry)
                            <tr wire:key="relation-{{ $entry['relation']->id }}">
                                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">
                                    {{ $entry['label'] }}
                                    @if ($entry['relation']->delay)
                                        <span>({{ __(':days日後', ['days' => $entry['relation']->delay]) }})</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('issues.show', [$entry['other']->project, $entry['other']]) }}" class="text-brand-bold hover:underline">
                                        {{ $entry['other']->tracker->name }} #{{ $entry['other']->id }} — {{ $entry['other']->subject }}
                                    </a>
                                </td>
                                @foreach ($this->relatedColumns as $key => $label)
                                    <td class="px-3 py-2 text-neutral-500">{{ \App\Support\Issues\RelatedIssueColumns::value($entry['other'], $key) }}</td>
                                @endforeach
                                <td class="px-3 py-2 text-right">
                                    @can('manageRelations', $issue)
                                        <button wire:click="deleteRelation({{ $entry['relation']->id }})" wire:confirm="{{ __('この関連を削除しますか?') }}"
                                            class="text-danger-bolder hover:underline">{{ __('削除') }}</button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @can('manageRelations', $issue)
            <form wire:submit="addRelation" class="mb-6 flex items-end gap-2">
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('関連種別') }}</label>
                    <select wire:model.live="relationType" class="mt-1 block rounded-md border-neutral-300 shadow-sm text-sm">
                        <option value="relates">{{ __('関連') }}</option>
                        <option value="blocks">{{ __('ブロックする') }}</option>
                        <option value="duplicates">{{ __('重複する') }}</option>
                        <option value="precedes">{{ __('先行') }}</option>
                        <option value="follows">{{ __('後続') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('課題ID') }}</label>
                    <input type="number" wire:model="relatedIssueId" placeholder="{{ __('例: 123') }}"
                        class="mt-1 block w-28 rounded-md border-neutral-300 shadow-sm text-sm">
                </div>
                <div data-related-search>
                    <label class="block text-xs font-medium text-neutral-700">{{ __('検索') }}</label>
                    <input type="text" wire:model.live.debounce.250ms="relatedSearch" placeholder="{{ __('#番号または件名...') }}"
                        class="mt-1 block w-56 rounded-md border-neutral-300 shadow-sm text-sm">
                    @if ($this->relatedSuggestions->isNotEmpty())
                        <ul class="absolute z-10 mt-1 max-h-48 w-72 overflow-y-auto rounded-md border border-neutral-200 bg-white text-sm shadow-sm">
                            @foreach ($this->relatedSuggestions as $suggestion)
                                <li wire:key="related-suggestion-{{ $suggestion->id }}">
                                    <button type="button" wire:click="pickRelated({{ $suggestion->id }})" class="block w-full px-3 py-1.5 text-left text-neutral-700 hover:bg-neutral-100">#{{ $suggestion->id }} {{ $suggestion->subject }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @if (in_array($relationType, ['precedes', 'follows'], true))
                    <div>
                        <label class="block text-xs font-medium text-neutral-700">{{ __('遅延日数') }}</label>
                        <input type="number" min="0" wire:model="relationDelay" placeholder="0"
                            class="mt-1 block w-20 rounded-md border-neutral-300 shadow-sm text-sm">
                    </div>
                @endif
                <button type="submit" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    {{ __('追加') }}
                </button>
            </form>
            @error('relatedIssueId') <p class="-mt-4 mb-6 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            @error('relationType') <p class="-mt-4 mb-6 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            @error('relationDelay') <p class="-mt-4 mb-6 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        @endcan
    @endif

    @if ($issue->timeEntries->isNotEmpty() || (! $issue->isLeaf() && $issue->totalSpentHours() > 0))
        <h2 class="text-sm font-semibold text-neutral-900 mb-2">
            {{ __('工数 (:hours 時間)', ['hours' => \App\Support\Format\Hours::format((float) $issue->timeEntries->sum('hours'))]) }}
            @if (! $issue->isLeaf())
                <span class="font-normal text-neutral-400">{{ __('(合計: :hours 時間)', ['hours' => \App\Support\Format\Hours::format($issue->totalSpentHours())]) }}</span>
            @endif
        </h2>
        <ul class="mb-6 space-y-1">
            @foreach ($issue->timeEntries as $entry)
                <li class="flex items-center justify-between text-sm">
                    <span>{{ $entry->spent_on->toDateString() }} — {{ $entry->user->displayName() }} — {{ $entry->activity->name }}</span>
                    <span class="text-neutral-500">{{ __(':hours 時間', ['hours' => $entry->hours]) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('履歴') }}</h2>
    <div class="mb-3 flex gap-1 border-b border-neutral-200 text-sm" data-history-tabs>
        @foreach ($this->historyTabs as $tabKey => $tabLabel)
            <button type="button" wire:click="setHistoryTab('{{ $tabKey }}')" wire:key="history-tab-{{ $tabKey }}"
                class="{{ $this->activeHistoryTab === $tabKey ? 'border-b-2 border-brand-bold font-semibold text-brand-bolder' : 'text-neutral-500 hover:text-neutral-800' }} px-3 py-1.5">
                {{ $tabLabel }}
            </button>
        @endforeach
    </div>

    @if ($this->activeHistoryTab === 'changesets')
        <ul class="mb-6 space-y-2" data-history-changesets>
            @foreach ($issue->changesets as $changeset)
                <li class="rounded-md border border-neutral-200 bg-white p-3 text-sm" wire:key="issue-changeset-{{ $changeset->id }}">
                    <a href="{{ route($changeset->repository->routeName('repository.show'), $changeset->repository->routeParameters(['changeset' => $changeset])) }}" class="font-mono text-brand-bold hover:underline">{{ $changeset->shortRevision() }}</a>
                    <span class="ml-2 text-xs text-neutral-500">{{ $changeset->committer }} — {{ $changeset->committed_on->format('Y-m-d H:i') }}</span>
                    <div class="mt-1 text-neutral-800">{{ $changeset->commentsHtml(firstLineOnly: true) }}</div>
                </li>
            @endforeach
        </ul>
    @else
    <ul class="space-y-3 mb-6">
        @forelse ($this->visibleJournals->filter(fn ($entry) => match ($this->activeHistoryTab) {
            'notes' => filled(trim((string) $entry->notes)),
            'properties' => $entry->details->isNotEmpty(),
            default => true,
        }) as $journal)
            @unless ($journal->isEmpty())
                <li wire:key="journal-{{ $journal->id }}" class="rounded-md border border-neutral-200 bg-white p-3 text-sm">
                    <div class="text-neutral-500 text-xs mb-1">
                        <x-avatar :user="$journal->user" :size="20" class="mr-1" />
                        {{ $journal->user->displayName() }} — {{ $journal->created_at->format('Y-m-d H:i') }}
                        @if ($journal->private_notes)
                            <span class="ml-1 rounded bg-warning-subtler px-1.5 py-0.5 text-warning-bold">{{ __('非公開') }}</span>
                        @endif
                        @if ($journal->notes && $journal->updatedBy !== null)
                            <span class="ml-1 italic" data-journal-edited>{{ __('(:user が編集 :time)', ['user' => $journal->updatedBy->displayName(), 'time' => $journal->updated_at->format('Y-m-d H:i')]) }}</span>
                        @elseif ($journal->notes && ! $journal->updated_at->equalTo($journal->created_at))
                            <span class="ml-1 italic">{{ __('(編集済み)') }}</span>
                        @endif
                    </div>
                    @foreach ($journal->details as $detail)
                        <div class="text-neutral-600 text-xs">
                            @if ($detail->property === 'attr' && $detail->prop_key === 'description')
                                {{ __(':labelが更新されました', ['label' => $this->journalDetailLabel($detail)]) }}
                                <a href="{{ route('issues.journal-detail-diff', [$project, $issue, $detail]) }}" class="text-brand-bold hover:underline">{{ __('(差分)') }}</a>
                            @elseif ($this->isLongTextCustomFieldDetail($detail))
                                {{ __(':labelが更新されました', ['label' => $this->journalDetailLabel($detail)]) }}
                                <a href="{{ route('issues.journal-detail-diff', [$project, $issue, $detail]) }}" class="text-brand-bold hover:underline">{{ __('(差分)') }}</a>
                            @elseif ($detail->property === 'attachment')
                                {{ $detail->new_value !== null ? __('添付ファイル「:name」が追加されました', ['name' => $detail->new_value]) : __('添付ファイル「:name」が削除されました', ['name' => $detail->old_value]) }}
                            @elseif ($detail->property === 'relation')
                                {{ $detail->new_value !== null ? __('関連「:relation #:id」が追加されました', ['relation' => $this->relationJournalLabel($detail->prop_key), 'id' => $detail->new_value]) : __('関連「:relation #:id」が削除されました', ['relation' => $this->relationJournalLabel($detail->prop_key), 'id' => $detail->old_value]) }}
                            @else
                                {{ $this->journalDetailLabel($detail) }}: {{ $detail->old_value ?? __('(未設定)') }} → {{ $detail->new_value ?? __('(未設定)') }}
                            @endif
                        </div>
                    @endforeach
                    @if ($journal->notes)
                        @if ($editingJournalId === $journal->id)
                            <div class="mt-1 space-y-1">
                                <textarea wire:model="editingJournalNotes" rows="3" class="block w-full rounded-md border-neutral-300 text-sm shadow-sm"></textarea>
                                @error('editingJournalNotes') <p class="text-xs text-danger-bolder">{{ $message }}</p> @enderror
                                @can('setNotesPrivate', $issue)
                                    <label class="flex items-center gap-1.5 text-xs text-neutral-700">
                                        <input type="checkbox" wire:model="editingJournalPrivate" class="rounded border-neutral-300">
                                        {{ __('非公開コメントにする') }}
                                    </label>
                                @endcan
                                <div class="flex gap-2">
                                    <button wire:click="saveJournalEdit" class="text-xs text-brand-bold hover:underline">{{ __('保存') }}</button>
                                    <button wire:click="cancelEditingJournal" class="text-xs text-neutral-500 hover:underline">{{ __('キャンセル') }}</button>
                                </div>
                            </div>
                        @else
                            <div class="prose prose-sm max-w-none mt-1 text-neutral-800">{!! $this->renderedNotes($journal) !!}</div>
                            <div class="mt-1 flex items-center gap-2">
                                @can('addNotes', $issue)
                                    <button wire:click="quote({{ $journal->id }})" class="text-xs text-brand-bold hover:underline">{{ __('引用') }}</button>
                                @endcan
                                @can('update', $journal)
                                    <button wire:click="startEditingJournal({{ $journal->id }})" class="text-xs text-brand-bold hover:underline">{{ __('編集') }}</button>
                                @endcan
                                <x-reaction-button :reactable="$journal" type="journal" />
                            </div>
                        @endif
                    @endif
                </li>
            @endunless
        @empty
            <li class="text-sm text-neutral-500">{{ __('履歴はありません。') }}</li>
        @endforelse
    </ul>
    @endif

    @can('addNotes', $issue)
        <form wire:submit="addComment" class="space-y-2">
            <textarea wire:model="comment" rows="3" placeholder="{{ __('コメントを追加') }}"
                class="{{ \App\Support\Preferences\UserPreferences::textareaClass(auth()->user()) }} block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
            @error('comment') <p class="text-sm text-danger-bolder">{{ $message }}</p> @enderror
            @can('setNotesPrivate', $issue)
                <label class="flex items-center gap-1.5 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="commentIsPrivate">
                    {{ __('非公開メモにする') }}
                </label>
            @endcan
            <button type="submit" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('コメントを追加') }}
            </button>
        </form>
    @endcan

    <x-hook name="issues.show.details_bottom" :issue="$issue" />
</div>
