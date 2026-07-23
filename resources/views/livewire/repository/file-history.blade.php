<?php

use App\Models\Changeset;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Collection;
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
     * Every changeset that touched this exact path, newest first — matches
     * Redmine's RepositoriesController#changes (@repository.latest_changesets
     * "path" filter), reading from the already-synced ChangesetFile rows
     * rather than shelling out to the VCS again. Renames/moves aren't
     * followed across paths (Redmine's own "history" doesn't need adapter
     * support for that here either — `git log --follow` isn't used by the
     * sync job), a documented simplification for the same reason the sync
     * job doesn't track renames as a single logical file.
     *
     * @return Collection<int, Changeset>
     */
    #[Computed]
    public function changesets(): Collection
    {
        return $this->repository->changesets()
            ->whereHas('files', fn ($query) => $query->where('path', $this->path))
            ->get();
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
            @foreach ($this->changesets as $changeset)
                <li wire:key="file-history-{{ $changeset->id }}" class="rounded-md border border-gray-200 bg-white p-3">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('repository.show', [$project, $changeset, 'path' => $this->path]) }}" class="font-mono text-sm text-indigo-600 hover:underline">
                            {{ $changeset->shortRevision() }}
                        </a>
                        <span class="text-xs text-gray-500">{{ $changeset->committer }} — {{ $changeset->committed_on->format('Y-m-d H:i') }}</span>
                    </div>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $changeset->comments }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
