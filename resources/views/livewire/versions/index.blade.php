<?php

use App\Models\Project;
use App\Models\Version;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('manageVersions', [Version::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function versions(): Collection
    {
        return $this->project->versions()->orderByDesc('due_date')->get();
    }

    public function delete(int $versionId): void
    {
        $version = Version::query()->where('project_id', $this->project->id)->findOrFail($versionId);
        $this->authorize('delete', $version);

        if ($version->issues()->exists()) {
            session()->flash('error', 'このバージョンが割り当てられた課題があるため削除できません。');

            return;
        }

        $version->delete();

        unset($this->versions);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — バージョン</h1>
        <a href="{{ route('versions.create', $project) }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規バージョン
        </a>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->versions as $version)
            <li class="px-4 py-3">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-medium text-gray-900">{{ $version->name }}</span>
                        <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">
                            {{ match ($version->status) {
                                \App\Enums\VersionStatus::Open => 'オープン',
                                \App\Enums\VersionStatus::Locked => 'ロック中',
                                \App\Enums\VersionStatus::Closed => 'クローズ',
                            } }}
                        </span>
                        @if ($version->due_date)
                            <span class="ml-2 text-xs text-gray-500">期日: {{ $version->due_date->toDateString() }}</span>
                        @endif
                        @if ($version->description)
                            <p class="mt-1 text-sm text-gray-600">{{ $version->description }}</p>
                        @endif
                    </div>
                    <div class="flex gap-3">
                        <a href="{{ route('versions.edit', [$project, $version]) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                        <button wire:click="delete({{ $version->id }})" wire:confirm="このバージョンを削除しますか?"
                            class="text-sm text-red-600 hover:underline">削除</button>
                    </div>
                </div>
                <div class="mt-2 flex gap-4 text-xs text-gray-500">
                    <span>予定工数: {{ Number::format($version->estimatedHours(), precision: 2) }} 時間</span>
                    <span>実績工数: {{ Number::format($version->spentHours(), precision: 2) }} 時間</span>
                    <span>残工数: {{ Number::format($version->estimatedRemainingHours(), precision: 2) }} 時間</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">バージョンがありません。</li>
        @endforelse
    </ul>
</div>
