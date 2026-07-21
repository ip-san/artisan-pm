<?php

use App\Enums\IssueRelationType;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
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
     * types (picked directly by the user), so only blocks/duplicates need
     * a computed reverse label.
     *
     * @var array<string, array{from: string, to: string}>
     */
    private const array RELATION_LABELS = [
        'relates' => ['from' => '関連', 'to' => '関連'],
        'blocks' => ['from' => 'ブロックする', 'to' => 'ブロックされている'],
        'duplicates' => ['from' => '重複する', 'to' => '重複されている'],
        'precedes' => ['from' => '先行', 'to' => '先行'],
        'follows' => ['from' => '後続', 'to' => '後続'],
    ];

    public Project $project;

    public Issue $issue;

    public string $comment = '';

    public bool $commentIsPrivate = false;

    public ?int $relatedIssueId = null;

    public string $relationType = 'relates';

    public function mount(Project $project, Issue $issue): void
    {
        $this->authorize('view', $issue);

        $this->project = $project;
        $this->issue = $issue->load(['tracker', 'status', 'priority', 'category', 'author', 'assignedTo', 'fixedVersion', 'journals.user', 'journals.details', 'customFieldValues', 'timeEntries.user', 'timeEntries.activity', 'relationsFrom.to.tracker', 'relationsFrom.to.project', 'relationsTo.from.tracker', 'relationsTo.from.project', 'parent.tracker', 'parent.status', 'children.tracker', 'children.status']);
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
            ],
            'relationType' => ['required', Rule::enum(IssueRelationType::class)],
        ]);

        $otherIssue = Issue::findOrFail($data['relatedIssueId']);
        $this->authorize('view', $otherIssue);

        IssueRelation::create([
            'issue_from_id' => $this->issue->id,
            'issue_to_id' => $otherIssue->id,
            'relation_type' => $data['relationType'],
        ]);

        $this->reset('relatedIssueId');
        $this->reloadRelations();
    }

    public function deleteRelation(int $relationId): void
    {
        $this->authorize('manageRelations', $this->issue);

        $relation = IssueRelation::query()
            ->where(fn ($q) => $q->where('issue_from_id', $this->issue->id)->orWhere('issue_to_id', $this->issue->id))
            ->findOrFail($relationId);

        $relation->delete();

        $this->reloadRelations();
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

    public function deleteAttachment(int $mediaId): void
    {
        $this->authorize('update', $this->issue);

        $this->issue->attachments()->firstWhere('id', $mediaId)?->delete();
    }

    public function deleteIssue(): void
    {
        $this->authorize('delete', $this->issue);

        $this->issue->delete();

        $this->redirect(route('issues.index', $this->project), navigate: true);
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
            <h1 class="text-xl font-semibold text-gray-900">{{ $issue->subject }}</h1>
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
    </div>

    @if ($issue->description)
        <div class="mb-6 whitespace-pre-line text-sm text-gray-700">{{ $issue->description }}</div>
    @endif

    @if ($this->customFieldDisplayValues->isNotEmpty())
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-gray-200 bg-white p-4 text-sm mb-6">
            @foreach ($this->customFieldDisplayValues as $entry)
                <div>
                    <span class="text-gray-500">{{ $entry['field']->name }}:</span>
                    {{ $entry['value'] === null || $entry['value'] === '' ? '-' : $entry['value'] }}
                </div>
            @endforeach
        </div>
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
                <li class="flex items-center justify-between text-sm">
                    <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                        {{ $media->file_name }}
                    </a>
                    <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                    @can('update', $issue)
                        <button wire:click="deleteAttachment({{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                            class="text-red-600 hover:underline">削除</button>
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
                    <select wire:model="relationType" class="mt-1 block rounded-md border-gray-300 shadow-sm text-sm">
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
                <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    追加
                </button>
            </form>
            @error('relatedIssueId') <p class="-mt-4 mb-6 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('relationType') <p class="-mt-4 mb-6 text-sm text-red-600">{{ $message }}</p> @enderror
        @endcan
    @endif

    @if ($issue->timeEntries->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-900 mb-2">
            工数 ({{ Number::format((float) $issue->timeEntries->sum('hours'), precision: 2) }} 時間)
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
                            {{ $this->journalDetailLabel($detail) }}: {{ $detail->old_value ?? '(未設定)' }} → {{ $detail->new_value ?? '(未設定)' }}
                        </div>
                    @endforeach
                    @if ($journal->notes)
                        <p class="mt-1 text-gray-800">{{ $journal->notes }}</p>
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
