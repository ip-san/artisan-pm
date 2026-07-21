<?php

use App\Models\Project;
use App\Models\Repository;
use App\Support\Scm\ScmTreeEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $path = '';

    /**
     * Browsing always reflects HEAD, not a specific historical revision —
     * unlike diff() (keyed by an immutable commit hash and cached
     * indefinitely), a tree listing "at HEAD" changes every time new
     * commits are synced, so it isn't cached here to avoid needing
     * separate invalidation plumbing for a moving target.
     */
    public function mount(Project $project, string $path = ''): void
    {
        $this->authorize('browse', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
        $this->path = trim($path, '/');
    }

    /**
     * @return array<int, ScmTreeEntry>
     */
    #[Computed]
    public function entries(): array
    {
        return $this->repository->adapter()->tree('HEAD', $this->path);
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
        <p class="text-sm text-gray-500">
            <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
        </p>
        <h1 class="text-xl font-semibold text-gray-900">ファイル一覧 (HEAD)</h1>
    </div>

    <nav class="mb-4 text-sm text-gray-600">
        <a href="{{ route('repository.browse', $project) }}" class="text-indigo-600 hover:underline">root</a>
        @foreach ($this->breadcrumbs as $crumb)
            /
            <a href="{{ route('repository.browse', [$project, $crumb['path']]) }}" class="text-indigo-600 hover:underline">
                {{ $crumb['name'] }}
            </a>
        @endforeach
    </nav>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->entries as $entry)
            <li wire:key="tree-{{ $entry->path }}" class="px-4 py-2 text-sm">
                @if ($entry->isDirectory)
                    <a href="{{ route('repository.browse', [$project, $entry->path]) }}" class="text-indigo-600 hover:underline">
                        📁 {{ $entry->name }}/
                    </a>
                @else
                    <a href="{{ route('repository.entry', [$project, $entry->path]) }}" class="text-indigo-600 hover:underline">
                        📄 {{ $entry->name }}
                    </a>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-gray-500">ファイルがありません。</li>
        @endforelse
    </ul>
</div>
