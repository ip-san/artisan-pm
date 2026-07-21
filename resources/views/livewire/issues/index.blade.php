<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Services\IssueService;
use App\Services\WorkflowService;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    #[Url]
    public string $statusFilter = 'open';

    /** @var array<int, int> */
    public array $selected = [];

    public ?int $bulkPriorityId = null;

    public ?int $bulkAssignedToId = null;

    public ?int $bulkFixedVersionId = null;

    public ?int $bulkStatusId = null;

    public ?int $bulkDoneRatio = null;

    public string $bulkComment = '';

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Issue::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function issues(): EloquentCollection
    {
        $query = $this->project->issues()
            ->with(['tracker', 'status', 'priority', 'assignedTo'])
            ->orderByDesc('id');

        if ($this->statusFilter !== 'all') {
            $isClosed = $this->statusFilter === 'closed';
            $query->whereHas('status', fn ($q) => $q->where('is_closed', $isClosed));
        }

        return $query->get();
    }

    #[Computed]
    public function canBulkEdit(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'edit_issues', $this->project);
    }

    /**
     * @return EloquentCollection<int, Issue>
     */
    #[Computed]
    public function selectedIssues(): EloquentCollection
    {
        if ($this->selected === []) {
            return new EloquentCollection;
        }

        return Issue::query()
            ->whereIn('id', $this->selected)
            ->where('project_id', $this->project->id)
            ->with('status')
            ->get();
    }

    /**
     * Only offered when every selected issue currently shares the same
     * status — each issue's own workflow otherwise governs which
     * transitions are valid, so a mixed selection has no single common
     * dropdown that's guaranteed safe for all of them.
     *
     * @return Collection<int, IssueStatus>
     */
    #[Computed]
    public function bulkStatusOptions(): Collection
    {
        $issues = $this->selectedIssues;

        if ($issues->isEmpty() || $issues->pluck('status_id')->unique()->count() > 1) {
            return collect();
        }

        return app(WorkflowService::class)->allowedTransitions($issues->first(), auth()->user());
    }

    #[Computed]
    public function priorities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->get();
    }

    #[Computed]
    public function projectMembers(): Collection
    {
        return $this->project->users;
    }

    #[Computed]
    public function projectVersions(): Collection
    {
        return $this->project->versions;
    }

    public function applyBulkEdit(): void
    {
        $issues = $this->selectedIssues;

        abort_if($issues->isEmpty(), 404);

        foreach ($issues as $issue) {
            $this->authorize('update', $issue);
        }

        // Scoped to this project so a crafted request can't pull in another
        // project's version, a non-member assignee, or a same-table
        // enumeration row that isn't actually a priority (mirrors the same
        // fix in issues/form.blade.php's single-issue save()).
        $data = $this->validate([
            'bulkPriorityId' => ['nullable', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'bulkAssignedToId' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
            'bulkFixedVersionId' => ['nullable', Rule::exists('versions', 'id')->where('project_id', $this->project->id)],
            'bulkStatusId' => ['nullable', 'exists:issue_statuses,id'],
            'bulkDoneRatio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'bulkComment' => ['nullable', 'string'],
        ]);

        $changes = array_filter([
            'priority_id' => $data['bulkPriorityId'],
            'assigned_to_id' => $data['bulkAssignedToId'],
            'fixed_version_id' => $data['bulkFixedVersionId'],
            'status_id' => $data['bulkStatusId'],
            'done_ratio' => $data['bulkDoneRatio'],
        ], fn ($value) => $value !== null);

        if (isset($changes['status_id'])) {
            $targetStatus = IssueStatus::findOrFail($changes['status_id']);

            foreach ($issues as $issue) {
                $this->authorize('transitionTo', [$issue, $targetStatus]);
            }
        }

        foreach ($issues as $issue) {
            app(IssueService::class)->update($issue, $changes, auth()->user(), $this->bulkComment ?: null);
        }

        $count = $issues->count();

        $this->reset(['selected', 'bulkPriorityId', 'bulkAssignedToId', 'bulkFixedVersionId', 'bulkStatusId', 'bulkDoneRatio', 'bulkComment']);
        unset($this->issues, $this->selectedIssues, $this->bulkStatusOptions);

        session()->flash('status', "{$count}件の課題を更新しました。");
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 課題</h1>
            <div class="mt-2 flex gap-3 text-sm">
                <button wire:click="$set('statusFilter', 'open')" class="{{ $statusFilter === 'open' ? 'font-semibold text-indigo-600' : 'text-gray-500' }}">未対応</button>
                <button wire:click="$set('statusFilter', 'closed')" class="{{ $statusFilter === 'closed' ? 'font-semibold text-indigo-600' : 'text-gray-500' }}">完了</button>
                <button wire:click="$set('statusFilter', 'all')" class="{{ $statusFilter === 'all' ? 'font-semibold text-indigo-600' : 'text-gray-500' }}">すべて</button>
            </div>
        </div>
        <div class="flex gap-2">
            @can('create', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.import', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    CSVインポート
                </a>
                <a href="{{ route('issues.create', $project) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    新規課題
                </a>
            @endcan
        </div>
    </div>

    @if ($this->canBulkEdit && count($selected) > 0)
        <form wire:submit="applyBulkEdit" class="mb-4 space-y-3 rounded-md border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm font-medium text-gray-900">{{ count($selected) }}件を選択中 — 変更する項目だけ設定してください</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700">ステータス</label>
                    <select wire:model="bulkStatusId" class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        @if ($this->bulkStatusOptions->isEmpty()) disabled @endif>
                        <option value="">変更なし</option>
                        @foreach ($this->bulkStatusOptions as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">優先度</label>
                    <select wire:model="bulkPriorityId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->priorities as $priority)
                            <option value="{{ $priority->id }}">{{ $priority->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">担当者</label>
                    <select wire:model="bulkAssignedToId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->projectMembers as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">対象バージョン</label>
                    <select wire:model="bulkFixedVersionId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ($this->projectVersions as $version)
                            <option value="{{ $version->id }}">{{ $version->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700">進捗率</label>
                    <select wire:model="bulkDoneRatio" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">変更なし</option>
                        @foreach ([0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $ratio)
                            <option value="{{ $ratio }}">{{ $ratio }}%</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700">コメント(任意)</label>
                <textarea wire:model="bulkComment" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm"></textarea>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    一括更新
                </button>
                <button type="button" wire:click="$set('selected', [])" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-white">
                    選択解除
                </button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    @if ($this->canBulkEdit)
                        <th class="px-4 py-2"></th>
                    @endif
                    <th class="px-4 py-2">#</th>
                    <th class="px-4 py-2">トラッカー</th>
                    <th class="px-4 py-2">ステータス</th>
                    <th class="px-4 py-2">優先度</th>
                    <th class="px-4 py-2">題名</th>
                    <th class="px-4 py-2">担当者</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->issues as $issue)
                    <tr>
                        @if ($this->canBulkEdit)
                            <td class="px-4 py-2">
                                <input type="checkbox" wire:model="selected" value="{{ $issue->id }}" class="rounded border-gray-300">
                            </td>
                        @endif
                        <td class="px-4 py-2 text-gray-500">{{ $issue->id }}</td>
                        <td class="px-4 py-2">{{ $issue->tracker->name }}</td>
                        <td class="px-4 py-2">{{ $issue->status->name }}</td>
                        <td class="px-4 py-2">{{ $issue->priority->name }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('issues.show', [$project, $issue]) }}" class="text-indigo-600 hover:underline">
                                {{ $issue->subject }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $issue->assignedTo?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-gray-500">課題がありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
