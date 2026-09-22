<?php

use App\Models\Project;
use App\Models\Version;
use App\Services\VersionService;
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

        app(VersionService::class)->delete($version);

        unset($this->versions);
    }

    public function closeCompleted(): void
    {
        $this->authorize('manageVersions', [Version::class, $this->project]);

        $this->project->closeCompletedVersions();

        unset($this->versions);

        session()->flash('status', '完了したバージョンをクローズしました。');
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ $project->name }} — バージョン</h1>
        <div class="flex gap-2">
            <button wire:click="closeCompleted" wire:confirm="期日を過ぎ、未クローズの課題が残っていないオープン/ロック中のバージョンをすべてクローズします。よろしいですか?"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                完了したバージョンをクローズ
            </button>
            <a href="{{ route('versions.create', $project) }}"
                class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                新規バージョン
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-success-subtlest p-3 text-sm text-success-bold">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->versions as $version)
            <li class="px-4 py-3">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-medium text-neutral-900">{{ $version->name }}</span>
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">
                            {{ match ($version->status) {
                                \App\Enums\VersionStatus::Open => 'オープン',
                                \App\Enums\VersionStatus::Locked => 'ロック中',
                                \App\Enums\VersionStatus::Closed => 'クローズ',
                            } }}
                        </span>
                        @if ($project->default_version_id === $version->id)
                            <span class="ml-2 rounded bg-warning-subtlest px-1.5 py-0.5 text-xs text-warning-bold">既定</span>
                        @endif
                        @if ($version->due_date)
                            <span class="ml-2 text-xs text-neutral-500">期日: {{ $version->due_date->toDateString() }}</span>
                        @endif
                        @if ($version->sharing !== \App\Enums\VersionSharing::None)
                            <span class="ml-2 rounded bg-brand-subtlest px-1.5 py-0.5 text-xs text-brand-bolder">{{ $version->sharing->label() }}</span>
                        @endif
                        @if ($version->description)
                            <p class="mt-1 text-sm text-neutral-600">{{ $version->description }}</p>
                        @endif
                        @if ($version->wiki_page_title && $version->wikiPage())
                            <p class="mt-1 text-xs">
                                <a href="{{ route('wiki.show', [$project, $version->wikiPage()]) }}" class="text-brand-bold hover:underline">
                                    {{ $version->wiki_page_title }}
                                </a>
                            </p>
                        @endif
                    </div>
                    <div class="flex gap-3">
                        <a href="{{ route('versions.edit', [$project, $version]) }}" class="text-sm text-brand-bold hover:underline">編集</a>
                        <button wire:click="delete({{ $version->id }})" wire:confirm="このバージョンを削除しますか?"
                            class="text-sm text-danger-bolder hover:underline">削除</button>
                    </div>
                </div>
                <div class="mt-2 flex gap-4 text-xs text-neutral-500">
                    <span>予定工数: {{ \App\Support\Format\Hours::format($version->estimatedHours()) }} 時間</span>
                    <span>実績工数: {{ \App\Support\Format\Hours::format($version->spentHours()) }} 時間</span>
                    <span>残工数: {{ \App\Support\Format\Hours::format($version->estimatedRemainingHours()) }} 時間</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">バージョンがありません。</li>
        @endforelse
    </ul>
</div>
