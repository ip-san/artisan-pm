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

    public string $compareFrom = '';

    public string $compareTo = '';

    public function compareSelected(): void
    {
        if ($this->compareFrom === '' || $this->compareTo === '' || $this->compareFrom === $this->compareTo) {
            return;
        }

        $this->redirect(
            route('repository.compare', [$this->project, 'from' => $this->compareFrom, 'to' => $this->compareTo]),
            navigate: true,
        );
    }

    public function sync(): void
    {
        $this->authorize('manage', [Repository::class, $this->project]);

        abort_if($this->repository === null, 404);

        RepositorySyncJob::dispatch($this->repository);

        // dispatch() only enqueues the job — it hasn't run yet, so this
        // must not claim the sync itself is done (misleading outside the
        // "sync" queue driver tests run under, where dispatch happens to
        // run inline). The changeset list intentionally isn't refreshed
        // here for the same reason; it'll reflect the sync on next load.
        session()->flash('status', '同期をキューに追加しました。しばらくしてから再読み込みしてください。');
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — リポジトリ</h1>
        <div class="flex gap-2">
            @if ($repository && auth()->user()?->can('browse', [Repository::class, $project]))
                <a href="{{ route('repository.browse', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ファイル一覧
                </a>
            @endif
            @if ($repository)
                <a href="{{ route('repository.stats', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    統計
                </a>
            @endif
            @if ($this->canManage)
                <a href="{{ route('repository.edit', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    設定
                </a>
                @if ($repository)
                    <a href="{{ route('repository.committers', $project) }}"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        コミッター設定
                    </a>
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
                        <th class="px-2 py-2">旧</th>
                        <th class="px-2 py-2">新</th>
                        <th class="px-4 py-2">リビジョン</th>
                        <th class="px-4 py-2">コミットメッセージ</th>
                        <th class="px-4 py-2">作成者</th>
                        <th class="px-4 py-2">日時</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->changesets as $changeset)
                        <tr wire:key="changeset-{{ $changeset->id }}">
                            <td class="px-2 py-2">
                                <input type="radio" wire:model="compareFrom" value="{{ $changeset->revision }}" class="border-gray-300">
                            </td>
                            <td class="px-2 py-2">
                                <input type="radio" wire:model="compareTo" value="{{ $changeset->revision }}" class="border-gray-300">
                            </td>
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
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">コミットがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->changesets->count() >= 2)
            <div class="mt-3">
                <button wire:click="compareSelected"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    選択したリビジョンを比較
                </button>
            </div>
        @endif
    @endif
</div>
