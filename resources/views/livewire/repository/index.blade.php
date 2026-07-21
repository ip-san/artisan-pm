<?php

use App\Jobs\RepositorySyncJob;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?Repository $repository = null;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Repository::class, $project]);

        $this->project = $project;
        $this->repository = $project->repository;
    }

    /**
     * @return Collection<int, \App\Models\Changeset>
     */
    #[Computed]
    public function changesets(): Collection
    {
        if ($this->repository === null) {
            return new Collection;
        }

        return $this->repository->changesets()->limit(100)->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user() !== null && auth()->user()->can('manage', [Repository::class, $this->project]);
    }

    public function sync(): void
    {
        $this->authorize('manage', [Repository::class, $this->project]);

        abort_if($this->repository === null, 404);

        RepositorySyncJob::dispatch($this->repository);

        unset($this->changesets);
        $this->repository->refresh();
        session()->flash('status', '同期しました。');
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — リポジトリ</h1>
        <div class="flex gap-2">
            @if ($repository && auth()->user()?->can('browse', [\App\Models\Repository::class, $project]))
                <a href="{{ route('repository.browse', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ファイル一覧
                </a>
            @endif
            @if ($this->canManage)
                <a href="{{ route('repository.edit', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    設定
                </a>
                @if ($repository)
                    <button wire:click="sync" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                        同期
                    </button>
                @endif
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($repository === null)
        <p class="text-sm text-gray-500">
            リポジトリが設定されていません。
            @if ($this->canManage)
                <a href="{{ route('repository.edit', $project) }}" class="text-indigo-600 hover:underline">設定する</a>
            @endif
        </p>
    @else
        <p class="mb-4 text-xs text-gray-500">
            種別: {{ $repository->type->value }} — パス: {{ $repository->path }}
            @if ($repository->last_synced_revision)
                — 最終同期リビジョン: {{ substr($repository->last_synced_revision, 0, 8) }}
            @endif
        </p>

        <div class="overflow-x-auto rounded-md border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-2">リビジョン</th>
                        <th class="px-4 py-2">コミットメッセージ</th>
                        <th class="px-4 py-2">作成者</th>
                        <th class="px-4 py-2">日時</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->changesets as $changeset)
                        <tr wire:key="changeset-{{ $changeset->id }}">
                            <td class="px-4 py-2 font-mono text-xs">
                                <a href="{{ route('repository.show', [$project, $changeset]) }}" class="text-indigo-600 hover:underline">
                                    {{ $changeset->shortRevision() }}
                                </a>
                            </td>
                            <td class="px-4 py-2">{{ Str::of($changeset->comments)->trim()->limit(80) }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $changeset->committer }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $changeset->committed_on->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-gray-500">コミットがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
