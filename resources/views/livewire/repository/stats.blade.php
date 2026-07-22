<?php

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Redmine's RepositoriesController#stats — commits-per-author and
 * commits-per-month breakdowns. `committer` is the SCM's raw free-text
 * field (see RepositorySyncService's docblock on why it isn't resolved to
 * a User here either), so authors are grouped by that exact string, same
 * as Redmine's own commits_per_author chart. Aggregation runs in PHP over
 * the full changeset history rather than a DB-specific date-grouping
 * query, keeping this portable and simple for what's normally a bounded
 * dataset (repository.index already caps its own listing at 100 for the
 * same "this isn't expected to be huge" reasoning).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
    }

    /**
     * @return EloquentCollection<int, \App\Models\Changeset>
     */
    #[Computed]
    public function changesets(): EloquentCollection
    {
        return $this->repository->changesets()->get(['committer', 'committed_on']);
    }

    /**
     * Committer (raw string) => commit count, highest first.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function commitsByAuthor(): Collection
    {
        return $this->changesets
            ->groupBy('committer')
            ->map(fn (EloquentCollection $group) => $group->count())
            ->sortDesc();
    }

    /**
     * "Y-m" => commit count, oldest month first.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function commitsByMonth(): Collection
    {
        return $this->changesets
            ->groupBy(fn ($changeset) => $changeset->committed_on->format('Y-m'))
            ->map(fn (EloquentCollection $group) => $group->count())
            ->sortKeys();
    }
}; ?>

<div class="max-w-3xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
    </p>

    <h1 class="mb-6 text-xl font-semibold text-gray-900">{{ $project->name }} — リポジトリ統計</h1>

    <div class="mb-8 rounded-md border border-gray-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">コミット数(合計 {{ $this->changesets->count() }} 件)</h2>

        @if ($this->changesets->isEmpty())
            <p class="text-sm text-gray-500">コミットがありません。</p>
        @else
            <h3 class="mb-2 text-xs font-medium text-gray-500">作成者別</h3>
            <div class="mb-6 space-y-2">
                @php $maxAuthorCount = $this->commitsByAuthor->max(); @endphp
                @foreach ($this->commitsByAuthor as $committer => $count)
                    <div wire:key="author-{{ $committer }}" class="flex items-center gap-2 text-sm">
                        <span class="w-40 shrink-0 truncate text-gray-700" title="{{ $committer }}">{{ $committer }}</span>
                        <div class="h-3 flex-1 overflow-hidden rounded bg-gray-100">
                            <div class="h-full bg-indigo-600" style="width: {{ $maxAuthorCount > 0 ? ($count / $maxAuthorCount) * 100 : 0 }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-gray-500">{{ $count }}</span>
                    </div>
                @endforeach
            </div>

            <h3 class="mb-2 text-xs font-medium text-gray-500">月別</h3>
            <div class="space-y-2">
                @php $maxMonthCount = $this->commitsByMonth->max(); @endphp
                @foreach ($this->commitsByMonth as $month => $count)
                    <div wire:key="month-{{ $month }}" class="flex items-center gap-2 text-sm">
                        <span class="w-40 shrink-0 text-gray-700">{{ $month }}</span>
                        <div class="h-3 flex-1 overflow-hidden rounded bg-gray-100">
                            <div class="h-full bg-indigo-600" style="width: {{ $maxMonthCount > 0 ? ($count / $maxMonthCount) * 100 : 0 }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-gray-500">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
