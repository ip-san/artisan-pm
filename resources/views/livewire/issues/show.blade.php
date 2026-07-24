<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
     * @var array<string, array{from: string, to: string}>
     */
    private const array RELATION_LABELS = [
        'relates' => ['from' => '関連', 'to' => '関連'],
        'blocks' => ['from' => 'ブロックする', 'to' => 'ブロックされている'],
        'duplicates' => ['from' => '重複する', 'to' => '重複されている'],
        'precedes' => ['from' => '先行', 'to' => '先行'],
        'follows' => ['from' => '後続', 'to' => '後続'],
        'copied_to' => ['from' => 'コピー先', 'to' => 'コピー元'],
    ];

    /**
     * Maps a relation journal's prop_key (including the reversed names
     * Redmine writes on the receiving end, e.g. "blocked") back to the
     * [type, side] pair used to index RELATION_LABELS above.
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

    public Project $project;

    public Issue $issue;

    public string $comment = '';

    public bool $commentIsPrivate = false;

    public ?int $relatedIssueId = null;

    public string $relationType = 'relates';

    public ?int $relationDelay = null;

    public ?int $newWatcherId = null;

    public ?int $moveToProjectId = null;

    public ?int $moveToTrackerId = null;

    /** @var array<int, string> attachment media id => description input value */
    public array $attachmentDescriptions = [];

    public function mount(Project $project, Issue $issue): void
    {
        $this->authorize('view', $issue);

        $this->project = $project;
        $this->issue = $issue->load(['tracker', 'status', 'priority', 'category', 'author', 'assignedTo', 'fixedVersion', 'journals.user', 'journals.details', 'customFieldValues', 'timeEntries.user', 'timeEntries.activity', 'relationsFrom.to.tracker', 'relationsFrom.to.project', 'relationsTo.from.tracker', 'relationsTo.from.project', 'parent.tracker', 'parent.status', 'children.tracker', 'children.status', 'watchers.user']);

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
        $from = $this->issue->relationsFrom->map(fn (IssueRelation $relation) => [
            'relation' => $relation,
            'other' => $relation->to,
            'label' => self::RELATION_LABELS[$relation->relation_type->value]['from'],
        ]);

        $to = $this->issue->relationsTo->map(fn (IssueRelation $relation) => [
            'relation' => $relation,
            'other' => $relation->from,
            'label' => self::RELATION_LABELS[$relation->relation_type->value]['to'],
        ]);

        return $from->concat($to)->sortBy(fn (array $entry) => $entry['relation']->id);
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

                    if ($other->project_id !== $this->issue->project_id && ! Setting::get('cross_project_issue_relations', false)) {
                        $fail('プロジェクトをまたぐ関連付けは許可されていません。');

                        return;
                    }

                    if ($this->issue->descendantIds()->contains($other->id) || $other->descendantIds()->contains($this->issue->id)) {
                        $fail('親子・祖先/子孫関係にある課題同士は関連付けできません。');

                        return;
                    }

                    if ($this->relationType === 'relates') {
                        $reverseExists = IssueRelation::query()
                            ->where('issue_from_id', $other->id)
                            ->where('issue_to_id', $this->issue->id)
                            ->where('relation_type', 'relates')
                            ->exists();

                        if ($reverseExists) {
                            $fail('この関連は既に登録されています。');
                        }
                    }

                    if ($this->relationType === 'blocks') {
                        $reverseBlocks = IssueRelation::query()
                            ->where('issue_from_id', $other->id)
                            ->where('issue_to_id', $this->issue->id)
                            ->where('relation_type', 'blocks')
                            ->exists();

                        if ($reverseBlocks) {
                            $fail('循環したブロック関係は作成できません。');
                        }
                    }

                    if ($this->relationType === 'precedes' && IssueRelation::wouldCreateCycle($this->issue, $other)) {
                        $fail('先行関係が循環しています。');
                    }

                    if ($this->relationType === 'follows' && IssueRelation::wouldCreateCycle($other, $this->issue)) {
                        $fail('先行関係が循環しています。');
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
        $this->issue->load('journals.user', 'journals.details');
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
        $this->issue->load('journals.user', 'journals.details');
    }

    private function reloadRelations(): void
    {
        $this->issue->load(['relationsFrom.to.tracker', 'relationsFrom.to.project', 'relationsTo.from.tracker', 'relationsTo.from.project']);
        unset($this->relations);
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

        return $type !== null ? self::RELATION_LABELS[$type][$side] : $propKey;
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
        $this->authorize('update', $this->issue);

        $journal = $this->visibleJournals->firstWhere('id', $journalId);

        if ($journal === null || blank($journal->notes)) {
            return;
        }

        $quoted = collect(explode("\n", $journal->notes))->map(fn (string $line) => "> {$line}")->implode("\n");

        $this->comment = "{$journal->user->name} wrote:\n{$quoted}\n\n";
    }

    public function addComment(): void
    {
        $this->authorize('update', $this->issue);

        $data = $this->validate(['comment' => ['required', 'string']]);

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
        $this->issue->load('journals.user', 'journals.details');
    }

    /**
     * @return Collection<int, Journal>
     */
    #[Computed]
    public function visibleJournals(): Collection
    {
        $user = auth()->user();

        if ($user->can('viewPrivateNotes', $this->issue)) {
            return $this->issue->journals;
        }

        // A user can always see their own private notes, even without
        // view_private_notes — matching Redmine's Journal#visible?.
        return $this->issue->journals
            ->filter(fn (Journal $journal) => ! $journal->private_notes || $journal->user_id === $user->id)
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

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function watcherCandidates(): Collection
    {
        $watchingIds = $this->issue->watchers->pluck('user_id');

        return $this->project->users->reject(fn (User $user) => $watchingIds->contains($user->id))->values();
    }

    public function addWatcher(): void
    {
        $this->authorize('manageWatchers', $this->issue);

        $data = $this->validate([
            'newWatcherId' => ['required', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
        ]);

        $this->issue->watchers()->firstOrCreate(['user_id' => $data['newWatcherId']]);

        $this->reset('newWatcherId');
        $this->issue->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    public function removeWatcher(int $userId): void
    {
        $this->authorize('manageWatchers', $this->issue);

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
        $this->issue->load('journals.user', 'journals.details');
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

    public function deleteIssue(): void
    {
        $this->authorize('delete', $this->issue);

        app(IssueService::class)->delete($this->issue);

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

        return $target?->trackers ?? collect();
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
            @if ($issue->parent)
                <p class="text-xs text-gray-500 mb-1">
                    <span class="text-gray-400">親課題:</span>
                    <a href="{{ route('issues.show', [$project, $issue->parent]) }}" class="text-indigo-600 hover:underline">
                        {{ $issue->parent->tracker->name }} #{{ $issue->parent->id }} — {{ $issue->parent->subject }}
                    </a>
                </p>
            @endif
            <p class="text-sm text-gray-500">{{ $issue->tracker->name }} #{{ $issue->id }}</p>
            <h1 class="text-xl font-semibold text-gray-900">
                {{ $issue->subject }}
                @if ($issue->is_private)
                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 align-middle text-xs font-normal text-gray-600">非公開</span>
                @endif
            </h1>
        </div>
        <div class="flex gap-2">
            <button wire:click="toggleWatch" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ $issue->isWatchedBy(auth()->user()) ? 'ウォッチ解除' : 'ウォッチ' }}
            </button>
            @can('create', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.create', $project) }}?issue_id={{ $issue->id }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    工数を記録
                </a>
            @endcan
            @can('create', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.create', $project) }}?copy_from={{ $issue->id }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    コピー
                </a>
            @endcan
            @can('update', $issue)
                <a href="{{ route('issues.edit', [$project, $issue]) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    編集
                </a>
            @endcan
            @can('delete', $issue)
                <button wire:click="deleteIssue" wire:confirm="この課題を削除しますか?この操作は取り消せません。"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    削除
                </button>
            @endcan
        </div>
    </div>

    @can('move', $issue)
        @if ($this->moveTargetProjects->isNotEmpty())
            <form wire:submit="moveIssue" class="mb-6 flex flex-wrap items-end gap-2 rounded-md border border-gray-200 bg-white p-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700">別のプロジェクトへ移動</label>
                    <select wire:model.live="moveToProjectId" class="mt-1 block rounded-md border-gray-300 text-sm">
                        <option value="">選択してください</option>
                        @foreach ($this->moveTargetProjects as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                    @error('moveToProjectId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @if ($moveToProjectId)
                    <div>
                        <label class="block text-xs font-medium text-gray-700">移動後のトラッカー</label>
                        <select wire:model="moveToTrackerId" class="mt-1 block rounded-md border-gray-300 text-sm">
                            <option value="">選択してください</option>
                            @foreach ($this->moveTargetTrackers as $candidateTracker)
                                <option value="{{ $candidateTracker->id }}">{{ $candidateTracker->name }}</option>
                            @endforeach
                        </select>
                        @error('moveToTrackerId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:confirm="移動するとカテゴリ・対象バージョン・親課題はリセットされます。よろしいですか?"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        移動
                    </button>
                @endif
            </form>
        @endif
    @endcan

    <div class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-gray-200 bg-white p-4 text-sm mb-6">
        <div><span class="text-gray-500">ステータス:</span> {{ $issue->status->name }}</div>
        <div><span class="text-gray-500">優先度:</span> {{ $issue->priority->name }}</div>
        <div><span class="text-gray-500">カテゴリ:</span> {{ $issue->category?->name ?? 'なし' }}</div>
        <div><span class="text-gray-500">作成者:</span> {{ $issue->author->name }}</div>
        <div><span class="text-gray-500">担当者:</span> {{ $issue->assignedTo?->name ?? '未割当' }}</div>
        <div><span class="text-gray-500">対象バージョン:</span> {{ $issue->fixedVersion?->name ?? 'なし' }}</div>
        <div><span class="text-gray-500">進捗率:</span> {{ $issue->done_ratio }}%</div>
        <div><span class="text-gray-500">開始日:</span> {{ $issue->start_date?->toDateString() ?? '-' }}</div>
        <div><span class="text-gray-500">期日:</span> {{ $issue->due_date?->toDateString() ?? '-' }}</div>
        <div>
            <span class="text-gray-500">予定工数:</span>
            {{ $issue->estimated_hours !== null ? Number::format((float) $issue->estimated_hours, precision: 2).' 時間' : '-' }}
            @if (! $issue->isLeaf() && $issue->totalEstimatedHours() > 0)
                <span class="text-gray-400">(合計: {{ Number::format($issue->totalEstimatedHours(), precision: 2) }} 時間)</span>
            @endif
        </div>
    </div>

    @if ($issue->description)
        <div class="prose prose-sm max-w-none mb-6 rounded-md border border-gray-200 bg-white p-4">
            {!! $this->renderedDescription !!}
        </div>
    @endif

    @if ($this->customFieldDisplayValues->isNotEmpty())
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-gray-200 bg-white p-4 text-sm mb-6">
            @foreach ($this->customFieldDisplayValues as $entry)
                <div>
                    <span class="text-gray-500">{{ $entry['field']->name }}:</span>
                    <x-custom-field-value :field="$entry['field']" :value="$entry['value']" />
                </div>
            @endforeach
        </div>
    @endif

    @if ($issue->watchers->isNotEmpty() || auth()->user()?->can('manageWatchers', $issue))
        <h2 class="text-sm font-semibold text-gray-900 mb-2">ウォッチャー ({{ $issue->watchers->count() }})</h2>
        <ul class="mb-3 flex flex-wrap gap-2">
            @foreach ($issue->watchers as $watcher)
                <li class="flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">
                    {{ $watcher->user->name }}
                    @can('manageWatchers', $issue)
                        <button wire:click="removeWatcher({{ $watcher->user_id }})" class="text-gray-400 hover:text-red-600" title="ウォッチャーから削除">×</button>
                    @endcan
                </li>
            @endforeach
        </ul>

        @can('manageWatchers', $issue)
            @if ($this->watcherCandidates->isNotEmpty())
                <form wire:submit="addWatcher" class="mb-6 flex items-end gap-2">
                    <div>
                        <select wire:model="newWatcherId" class="block rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">ウォッチャーを追加...</option>
                            @foreach ($this->watcherCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        追加
                    </button>
                </form>
                @error('newWatcherId') <p class="-mt-4 mb-6 text-sm text-red-600">{{ $message }}</p> @enderror
            @else
                <div class="mb-6"></div>
            @endif
        @endcan
    @endif

    @if ($issue->children->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-900 mb-2">サブタスク</h2>
        <ul class="mb-6 space-y-1">
            @foreach ($issue->children as $child)
                <li class="flex items-center justify-between text-sm rounded-md border border-gray-200 bg-white px-3 py-2">
                    <a href="{{ route('issues.show', [$project, $child]) }}" class="text-indigo-600 hover:underline">
                        {{ $child->tracker->name }} #{{ $child->id }} — {{ $child->subject }}
                    </a>
                    <span class="text-gray-500">{{ $child->status->name }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @php $attachments = $issue->attachments(); @endphp
    @if ($attachments->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-900 mb-2">添付ファイル</h2>
        <ul class="mb-6 space-y-1">
            @foreach ($attachments as $media)
                <li class="py-1 text-sm" wire:key="issue-attachment-{{ $media->id }}">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <x-attachment-thumbnail :media="$media" />
                            <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                                {{ $media->file_name }}
                            </a>
                        </span>
                        <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                        <x-download-count :media="$media" />
                        @can('update', $issue)
                            <button wire:click="deleteAttachment({{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                                class="text-red-600 hover:underline">削除</button>
                        @endcan
                    </div>
                    @can('update', $issue)
                        <div class="mt-1 flex items-center gap-2">
                            <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                                class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                            <button wire:click="updateAttachmentDescription({{ $media->id }})"
                                class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                        </div>
                    @elseif ($media->getCustomProperty('description'))
                        <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->relations->isNotEmpty() || auth()->user()?->can('manageRelations', $issue))
        <h2 class="text-sm font-semibold text-gray-900 mb-2">関連課題</h2>
        <ul class="mb-4 space-y-1">
            @foreach ($this->relations as $entry)
                <li class="flex items-center justify-between text-sm rounded-md border border-gray-200 bg-white px-3 py-2">
                    <span>
                        <span class="text-gray-500">{{ $entry['label'] }}:</span>
                        <a href="{{ route('issues.show', [$entry['other']->project, $entry['other']]) }}" class="text-indigo-600 hover:underline">
                            {{ $entry['other']->tracker->name }} #{{ $entry['other']->id }} — {{ $entry['other']->subject }}
                        </a>
                        @if ($entry['relation']->delay)
                            <span class="text-gray-500">({{ $entry['relation']->delay }}日後)</span>
                        @endif
                    </span>
                    @can('manageRelations', $issue)
                        <button wire:click="deleteRelation({{ $entry['relation']->id }})" wire:confirm="この関連を削除しますか?"
                            class="text-red-600 hover:underline">削除</button>
                    @endcan
                </li>
            @endforeach
        </ul>

        @can('manageRelations', $issue)
            <form wire:submit="addRelation" class="mb-6 flex items-end gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-700">関連種別</label>
                    <select wire:model.live="relationType" class="mt-1 block rounded-md border-gray-300 shadow-sm text-sm">
                        <option value="relates">関連</option>
                        <option value="blocks">ブロックする</option>
                        <option value="duplicates">重複する</option>
                        <option value="precedes">先行</option>
                        <option value="follows">後続</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">課題ID</label>
                    <input type="number" wire:model="relatedIssueId" placeholder="例: 123"
                        class="mt-1 block w-28 rounded-md border-gray-300 shadow-sm text-sm">
                </div>
                @if (in_array($relationType, ['precedes', 'follows'], true))
                    <div>
                        <label class="block text-xs font-medium text-gray-700">遅延日数</label>
                        <input type="number" min="0" wire:model="relationDelay" placeholder="0"
                            class="mt-1 block w-20 rounded-md border-gray-300 shadow-sm text-sm">
                    </div>
                @endif
                <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    追加
                </button>
            </form>
            @error('relatedIssueId') <p class="-mt-4 mb-6 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('relationType') <p class="-mt-4 mb-6 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('relationDelay') <p class="-mt-4 mb-6 text-sm text-red-600">{{ $message }}</p> @enderror
        @endcan
    @endif

    @if ($issue->timeEntries->isNotEmpty() || (! $issue->isLeaf() && $issue->totalSpentHours() > 0))
        <h2 class="text-sm font-semibold text-gray-900 mb-2">
            工数 ({{ Number::format((float) $issue->timeEntries->sum('hours'), precision: 2) }} 時間)
            @if (! $issue->isLeaf())
                <span class="font-normal text-gray-400">(合計: {{ Number::format($issue->totalSpentHours(), precision: 2) }} 時間)</span>
            @endif
        </h2>
        <ul class="mb-6 space-y-1">
            @foreach ($issue->timeEntries as $entry)
                <li class="flex items-center justify-between text-sm">
                    <span>{{ $entry->spent_on->toDateString() }} — {{ $entry->user->name }} — {{ $entry->activity->name }}</span>
                    <span class="text-gray-500">{{ $entry->hours }} 時間</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="text-sm font-semibold text-gray-900 mb-2">履歴</h2>
    <ul class="space-y-3 mb-6">
        @forelse ($this->visibleJournals as $journal)
            @unless ($journal->isEmpty())
                <li class="rounded-md border border-gray-200 bg-white p-3 text-sm">
                    <div class="text-gray-500 text-xs mb-1">
                        {{ $journal->user->name }} — {{ $journal->created_at->format('Y-m-d H:i') }}
                        @if ($journal->private_notes)
                            <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-700">非公開</span>
                        @endif
                    </div>
                    @foreach ($journal->details as $detail)
                        <div class="text-gray-600 text-xs">
                            @if ($detail->property === 'attr' && $detail->prop_key === 'description')
                                {{ $this->journalDetailLabel($detail) }}が更新されました
                                <a href="{{ route('issues.journal-detail-diff', [$project, $issue, $detail]) }}" class="text-indigo-600 hover:underline">(差分)</a>
                            @elseif ($this->isLongTextCustomFieldDetail($detail))
                                {{ $this->journalDetailLabel($detail) }}が更新されました
                                <a href="{{ route('issues.journal-detail-diff', [$project, $issue, $detail]) }}" class="text-indigo-600 hover:underline">(差分)</a>
                            @elseif ($detail->property === 'attachment')
                                添付ファイル「{{ $detail->new_value ?? $detail->old_value }}」が{{ $detail->new_value !== null ? '追加' : '削除' }}されました
                            @elseif ($detail->property === 'relation')
                                関連「{{ $this->relationJournalLabel($detail->prop_key) }} #{{ $detail->new_value ?? $detail->old_value }}」が{{ $detail->new_value !== null ? '追加' : '削除' }}されました
                            @else
                                {{ $this->journalDetailLabel($detail) }}: {{ $detail->old_value ?? '(未設定)' }} → {{ $detail->new_value ?? '(未設定)' }}
                            @endif
                        </div>
                    @endforeach
                    @if ($journal->notes)
                        <div class="prose prose-sm max-w-none mt-1 text-gray-800">{!! $this->renderedNotes($journal) !!}</div>
                        @can('update', $issue)
                            <button wire:click="quote({{ $journal->id }})" class="mt-1 text-xs text-indigo-600 hover:underline">引用</button>
                        @endcan
                    @endif
                </li>
            @endunless
        @empty
            <li class="text-sm text-gray-500">履歴はありません。</li>
        @endforelse
    </ul>

    @can('update', $issue)
        <form wire:submit="addComment" class="space-y-2">
            <textarea wire:model="comment" rows="3" placeholder="コメントを追加"
                class="block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('comment') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @can('setNotesPrivate', $issue)
                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                    <input type="checkbox" wire:model="commentIsPrivate">
                    非公開メモにする
                </label>
            @endcan
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                コメントを追加
            </button>
        </form>
    @endcan

    <x-hook name="issues.show.details_bottom" :issue="$issue" />
</div>
