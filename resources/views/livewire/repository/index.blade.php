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

    public function mount(Project $project, ?string $repositoryParam = null): void
    {
        $this->authorize('viewAny', [Repository::class, $project]);

        $this->project = $project;
        $this->repository = $project->resolveRepository($repositoryParam);
    }

    /**
     * The repository switcher — every repository this project has, default
     * first. Only worth rendering once a project actually has more than
     * one (the create/switch UI would otherwise just repeat what the page
     * header already shows for the single repository).
     *
     * Each row's `project` relation is set to `$this->project` directly
     * rather than eager-loaded: `routeParameters()` reads it to build that
     * row's link, and since it's necessarily the same Project instance
     * already in memory, an eager-load query would only re-fetch a row we
     * already have (confirmed via query count: skipping it drops this
     * from N+1 "select * from projects" queries to zero).
     *
     * @return Collection<int, Repository>
     */
    #[Computed]
    public function allRepositories(): Collection
    {
        return $this->project->repositories()
            ->orderByDesc('is_default')
            ->orderBy('identifier')
            ->get()
            ->each(fn (Repository $repository) => $repository->setRelation('project', $this->project));
    }

    /**
     * Matches Redmine's own "set as default" affordance for a
     * multi-repository project — flips is_default on the target and lets
     * Repository's own `updated` hook (check_default) sweep every other
     * repository in the project back to false, so this only ever needs to
     * touch the one row a manager clicked.
     */
    public function setDefault(int $repositoryId): void
    {
        $this->authorize('manage', [Repository::class, $this->project]);

        $target = $this->project->repositories()->whereKey($repositoryId)->firstOrFail();
        $target->update(['is_default' => true]);

        // The sweep triggered by the update above may have flipped
        // $this->repository's own is_default out from under it (if it
        // wasn't the repository just made default) — refreshed here so
        // routeName()/routeParameters() reflect the new state for the
        // rest of this request rather than the stale value already
        // loaded into this property.
        $this->repository = $this->repository?->fresh();
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
        if ($this->compareFrom === '' || $this->compareTo === '' || $this->compareFrom === $this->compareTo || $this->repository === null) {
            return;
        }

        $this->redirect(
            route($this->repository->routeName('repository.compare'), $this->repository->routeParameters(['from' => $this->compareFrom, 'to' => $this->compareTo])),
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
                <a href="{{ route($repository->routeName('repository.browse'), $repository->routeParameters()) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ファイル一覧
                </a>
            @endif
            @if ($repository)
                <a href="{{ route($repository->routeName('repository.stats'), $repository->routeParameters()) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    統計
                </a>
            @endif
            @if ($this->canManage)
                <a href="{{ $repository ? route($repository->routeName('repository.edit'), $repository->routeParameters()) : route('repository.edit', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    設定
                </a>
                @if ($repository)
                    <a href="{{ route($repository->routeName('repository.committers'), $repository->routeParameters()) }}"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        コミッター設定
                    </a>
                    <button wire:click="sync" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                        同期
                    </button>
                @endif
                <a href="{{ route('repository.create', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    リポジトリを追加
                </a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($this->allRepositories->count() > 1)
        <div class="mb-6 overflow-x-auto rounded-md border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-2">識別子</th>
                        <th class="px-4 py-2">種別</th>
                        <th class="px-4 py-2">パス</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($this->allRepositories as $candidate)
                        <tr wire:key="repo-switcher-{{ $candidate->id }}" class="{{ $repository && $candidate->is($repository) ? 'bg-indigo-50' : '' }}">
                            <td class="px-4 py-2">
                                <a href="{{ route($candidate->routeName('repository.index'), $candidate->routeParameters()) }}" class="text-indigo-600 hover:underline">
                                    {{ $candidate->identifierParam() }}
                                </a>
                                @if ($candidate->is_default)
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">既定</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ $candidate->type->value }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $candidate->path }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($this->canManage && ! $candidate->is_default)
                                    <button wire:click="setDefault({{ $candidate->id }})" class="text-xs text-indigo-600 hover:underline">
                                        既定にする
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
                                <a href="{{ route($repository->routeName('repository.show'), $repository->routeParameters(['changeset' => $changeset])) }}" class="text-indigo-600 hover:underline">
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
