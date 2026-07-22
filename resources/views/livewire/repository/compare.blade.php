<?php

use App\Models\Changeset;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public Changeset $fromChangeset;

    public Changeset $toChangeset;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /**
     * The two endpoints are normalized so `from` is always the older
     * changeset regardless of which radio the user picked first — the
     * same convention the wiki version diff uses.
     */
    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;

        $endpoints = collect([$this->from, $this->to])
            ->map(fn (string $revision) => $repository->changesets()->where('revision', $revision)->first());

        abort_if($endpoints->contains(null) || $this->from === $this->to, 404);

        [$this->fromChangeset, $this->toChangeset] = $endpoints
            ->sortBy([['committed_on', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Both endpoints are immutable commits, so the range diff is cached
     * indefinitely — same reasoning as the single-revision diff on
     * repository.show.
     */
    #[Computed]
    public function diff(): string
    {
        return Cache::rememberForever(
            "repository:{$this->repository->id}:diff:{$this->fromChangeset->revision}..{$this->toChangeset->revision}",
            fn () => $this->repository->adapter()->diff($this->toChangeset->revision, $this->fromChangeset->revision),
        );
    }
}; ?>

<div class="max-w-4xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
    </p>

    <h1 class="mb-1 text-xl font-semibold text-gray-900 font-mono">
        {{ $fromChangeset->shortRevision() }} 〜 {{ $toChangeset->shortRevision() }}
    </h1>
    <p class="mb-4 text-sm text-gray-500">
        <a href="{{ route('repository.show', [$project, $fromChangeset]) }}" class="text-indigo-600 hover:underline">{{ $fromChangeset->shortRevision() }}</a>
        ({{ $fromChangeset->committed_on->format('Y-m-d H:i') }})
        から
        <a href="{{ route('repository.show', [$project, $toChangeset]) }}" class="text-indigo-600 hover:underline">{{ $toChangeset->shortRevision() }}</a>
        ({{ $toChangeset->committed_on->format('Y-m-d H:i') }})
        までの差分
    </p>

    @if (trim($this->diff) === '')
        <p class="text-sm text-gray-500">このリビジョン間に差分はありません。</p>
    @else
        <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-900 p-4 text-xs text-gray-100">{{ $this->diff }}</pre>
    @endif
</div>
