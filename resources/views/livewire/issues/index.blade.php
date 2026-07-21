<?php

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    #[Url]
    public string $statusFilter = 'open';

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Issue::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function issues(): Collection
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
        @can('create', [\App\Models\Issue::class, $project])
            <a href="{{ route('issues.create', $project) }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規課題
            </a>
        @endcan
    </div>

    <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
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
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500">課題がありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
