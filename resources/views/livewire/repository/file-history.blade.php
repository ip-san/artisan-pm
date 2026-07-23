<?php

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $path;

    public function mount(Project $project, string $path): void
    {
        $this->authorize('browse', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
        $this->path = trim($path, '/');
    }

    /**
     * Every changeset that touched this file, newest first, following
     * rename/move chains back through the file's earlier paths — matches
     * Redmine's RepositoriesController#changes combined with `git log
     * --follow`-style rename tracking. Walks changesets newest-first (the
     * only direction that works: a rename's ChangesetFile only records
     * *where the file came from*, not where it goes next), switching to
     * the pre-rename path as soon as a changeset's matching file row
     * carries a from_path, so earlier changesets are then matched against
     * that older path instead. Reads from the already-synced
     * ChangesetFile rows rather than shelling out to the VCS again.
     *
     * @return Collection<int, array{changeset: \App\Models\Changeset, path: string}>
     */
    #[Computed]
    public function changesets(): Collection
    {
        $currentPath = $this->path;
        $matches = collect();

        $changesets = $this->repository->changesets()->with('files')->orderByDesc('id')->get();

        foreach ($changesets as $changeset) {
            $file = $changeset->files->firstWhere('path', $currentPath);

            if ($file === null) {
                continue;
            }

            $matches->push(['changeset' => $changeset, 'path' => $currentPath]);

            if ($file->from_path !== null) {
                $currentPath = $file->from_path;
            }
        }

        return $matches;
    }
}; ?>

<div>
    <div class="mb-6">
        <p class="text-sm text-gray-500">
            <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
            /
            <a href="{{ route('repository.entry', [$project, $this->path]) }}" class="text-indigo-600 hover:underline">
                {{ $this->path }}
            </a>
        </p>
        <h1 class="text-xl font-semibold text-gray-900 font-mono">履歴: {{ $this->path }}</h1>
    </div>

    @if ($this->changesets->isEmpty())
        <p class="text-sm text-gray-500">このファイルの変更履歴が見つかりませんでした。</p>
    @else
        <ul class="space-y-2">
            @foreach ($this->changesets as $match)
                @php $changeset = $match['changeset']; @endphp
                <li wire:key="file-history-{{ $changeset->id }}" class="rounded-md border border-gray-200 bg-white p-3">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('repository.show', [$project, $changeset, 'path' => $match['path']]) }}" class="font-mono text-sm text-indigo-600 hover:underline">
                            {{ $changeset->shortRevision() }}
                        </a>
                        <span class="text-xs text-gray-500">{{ $changeset->committer }} — {{ $changeset->committed_on->format('Y-m-d H:i') }}</span>
                        @if ($match['path'] !== $this->path)
                            <span class="text-xs text-gray-400 font-mono">({{ $match['path'] }})</span>
                        @endif
                    </div>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $changeset->comments }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
