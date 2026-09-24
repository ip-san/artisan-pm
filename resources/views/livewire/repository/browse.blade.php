<?php

use App\Enums\ScmCapability;
use App\Models\Project;
use App\Models\Repository;
use App\Support\Scm\ScmTreeEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $path = '';

    /**
     * The branch or tag browsed (Redmine's `rev`), or null for HEAD. Only a
     * name the repository lists is used (Repository::revisionFor()).
     */
    #[Url]
    public ?string $rev = null;

    /**
     * Browsing always reflects HEAD, not a specific historical revision —
     * unlike diff() (keyed by an immutable commit hash and cached
     * indefinitely), a tree listing "at HEAD" changes every time new
     * commits are synced, so it isn't cached here to avoid needing
     * separate invalidation plumbing for a moving target.
     */
    public function mount(Project $project, ?string $path = null, ?string $repositoryParam = null): void
    {
        $this->authorize('browse', [Repository::class, $project]);

        $repository = $project->resolveRepository($repositoryParam);
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
        $this->path = trim($path ?? '', '/');
    }

    /**
     * @return array<int, ScmTreeEntry>
     */
    /**
     * B'-03: a Filesystem repository has no per-file history to link to.
     */
    #[Computed]
    public function supportsHistory(): bool
    {
        return $this->repository->supports(ScmCapability::Log);
    }

    #[Computed]
    public function entries(): array
    {
        return $this->repository->adapter()->tree($this->revision, $this->path);
    }

    /**
     * @return array{branches: list<string>, tags: list<string>}
     */
    #[Computed]
    public function refs(): array
    {
        return $this->repository->refs();
    }

    #[Computed]
    public function revision(): string
    {
        return $this->repository->revisionFor($this->rev);
    }

    /**
     * The `rev` to carry on links, when a known branch or tag is browsed.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function revParameter(): array
    {
        return $this->revision === 'HEAD' ? [] : ['rev' => (string) $this->rev];
    }

    public function updatedRev(): void
    {
        unset($this->entries, $this->revision, $this->revParameter);
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    #[Computed]
    public function breadcrumbs(): array
    {
        if ($this->path === '') {
            return [];
        }

        $crumbs = [];
        $accumulated = [];

        foreach (explode('/', $this->path) as $segment) {
            $accumulated[] = $segment;
            $crumbs[] = ['name' => $segment, 'path' => implode('/', $accumulated)];
        }

        return $crumbs;
    }
}; ?>

<div>
    <div class="mb-6">
        <p class="text-sm text-neutral-500">
            <a href="{{ route($repository->routeName('repository.index'), $repository->routeParameters()) }}" class="text-brand-bold hover:underline">{{ __('リポジトリ') }}</a>
        </p>
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('ファイル一覧') }} ({{ $this->revParameter === [] ? 'HEAD' : $rev }})</h1>
    </div>

    @if ($this->refs['branches'] !== [] || $this->refs['tags'] !== [])
        <div class="mb-4 flex flex-wrap items-center gap-3 text-sm text-neutral-700" data-repository-refs>
            @if ($this->refs['branches'] !== [])
                <label class="flex items-center gap-1">
                    {{ __('ブランチ') }}
                    <select wire:model.live="rev" class="rounded-md border-neutral-300 text-sm">
                        <option value="">HEAD</option>
                        @foreach ($this->refs['branches'] as $branch)
                            <option value="{{ $branch }}">{{ $branch }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if ($this->refs['tags'] !== [])
                <label class="flex items-center gap-1">
                    {{ __('タグ') }}
                    <select wire:model.live="rev" class="rounded-md border-neutral-300 text-sm">
                        <option value="">HEAD</option>
                        @foreach ($this->refs['tags'] as $tag)
                            <option value="{{ $tag }}">{{ $tag }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
    @endif

    <nav class="mb-4 text-sm text-neutral-600">
        <a href="{{ route($repository->routeName('repository.browse'), $repository->routeParameters($this->revParameter)) }}" class="text-brand-bold hover:underline">root</a>
        @foreach ($this->breadcrumbs as $crumb)
            /
            <a href="{{ route($repository->routeName('repository.browse'), $repository->routeParameters(['path' => $crumb['path'], ...$this->revParameter])) }}" class="text-brand-bold hover:underline">
                {{ $crumb['name'] }}
            </a>
        @endforeach
    </nav>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
        @forelse ($this->entries as $entry)
            <li wire:key="tree-{{ $entry->path }}" class="flex items-center justify-between px-4 py-2 text-sm">
                @if ($entry->isDirectory)
                    <a href="{{ route($repository->routeName('repository.browse'), $repository->routeParameters(['path' => $entry->path, ...$this->revParameter])) }}" class="text-brand-bold hover:underline">
                        📁 {{ $entry->name }}/
                    </a>
                @else
                    <a href="{{ route($repository->routeName('repository.entry'), $repository->routeParameters(['path' => $entry->path, ...$this->revParameter])) }}" class="text-brand-bold hover:underline">
                        📄 {{ $entry->name }}
                    </a>
                    @if ($this->supportsHistory)
                        <a href="{{ route($repository->routeName('repository.file-history'), $repository->routeParameters(['path' => $entry->path])) }}" class="text-xs text-neutral-500 hover:underline">
                            {{ __('履歴') }}
                        </a>
                    @endif
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-neutral-500">{{ __('ファイルがありません。') }}</li>
        @endforelse
    </ul>
</div>
