<?php

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Issue $issue;

    public string $comment = '';

    public function mount(Project $project, Issue $issue): void
    {
        $this->authorize('view', $issue);

        $this->project = $project;
        $this->issue = $issue->load(['tracker', 'status', 'priority', 'author', 'assignedTo', 'fixedVersion', 'journals.user', 'journals.details', 'customFieldValues']);
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
        ]);

        $this->reset('comment');
        $this->issue->load('journals.user', 'journals.details');
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
}; ?>

<div class="max-w-3xl">
    <div class="flex items-start justify-between mb-4">
        <div>
            <p class="text-sm text-gray-500">{{ $issue->tracker->name }} #{{ $issue->id }}</p>
            <h1 class="text-xl font-semibold text-gray-900">{{ $issue->subject }}</h1>
        </div>
        <div class="flex gap-2">
            <button wire:click="toggleWatch" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ $issue->isWatchedBy(auth()->user()) ? 'ウォッチ解除' : 'ウォッチ' }}
            </button>
            @can('update', $issue)
                <a href="{{ route('issues.edit', [$project, $issue]) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    編集
                </a>
            @endcan
        </div>
    </div>

    <div class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-gray-200 bg-white p-4 text-sm mb-6">
        <div><span class="text-gray-500">ステータス:</span> {{ $issue->status->name }}</div>
        <div><span class="text-gray-500">優先度:</span> {{ $issue->priority->name }}</div>
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

    <h2 class="text-sm font-semibold text-gray-900 mb-2">履歴</h2>
    <ul class="space-y-3 mb-6">
        @forelse ($issue->journals as $journal)
            @unless ($journal->isEmpty())
                <li class="rounded-md border border-gray-200 bg-white p-3 text-sm">
                    <div class="text-gray-500 text-xs mb-1">
                        {{ $journal->user->name }} — {{ $journal->created_at->format('Y-m-d H:i') }}
                    </div>
                    @foreach ($journal->details as $detail)
                        <div class="text-gray-600 text-xs">
                            {{ $detail->prop_key }}: {{ $detail->old_value ?? '(未設定)' }} → {{ $detail->new_value ?? '(未設定)' }}
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
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                コメントを追加
            </button>
        </form>
    @endcan
</div>
