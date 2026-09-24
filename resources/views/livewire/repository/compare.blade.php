<?php

use App\Enums\ScmCapability;
use App\Models\Changeset;
use App\Models\Project;
use App\Models\Repository;
use App\Support\Scm\DisplayLimits;
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
    public function mount(Project $project, ?string $repositoryParam = null): void
    {
        $this->authorize('viewAny', [Repository::class, $project]);

        $repository = $project->resolveRepository($repositoryParam);
        abort_if($repository === null, 404);
        // B'-03: a Filesystem repository has no diff — the page doesn't apply.
        abort_unless($repository->supports(ScmCapability::Diff), 404);

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

    /**
     * The diff cut to diff_max_lines_displayed (applied after the cache).
     *
     * @return array{text: string, truncated: bool}
     */
    #[Computed]
    public function shownDiff(): array
    {
        return DisplayLimits::truncateDiff($this->diff);
    }
}; ?>

<div class="max-w-4xl">
    <p class="mb-2 text-sm text-neutral-500">
        <a href="{{ route($repository->routeName('repository.index'), $repository->routeParameters()) }}" class="text-brand-bold hover:underline">{{ __('リポジトリ') }}</a>
    </p>

    <h1 class="mb-1 text-xl font-semibold text-neutral-900 font-mono">
        {{ $fromChangeset->shortRevision() }} 〜 {{ $toChangeset->shortRevision() }}
    </h1>
    @php
        $fromLink = '<a href="'.e(route($repository->routeName('repository.show'), $repository->routeParameters(['changeset' => $fromChangeset]))).'" class="text-brand-bold hover:underline">'.e($fromChangeset->shortRevision()).'</a>';
        $toLink = '<a href="'.e(route($repository->routeName('repository.show'), $repository->routeParameters(['changeset' => $toChangeset]))).'" class="text-brand-bold hover:underline">'.e($toChangeset->shortRevision()).'</a>';
    @endphp
    <p class="mb-4 text-sm text-neutral-500">
        {!! __(':from (:from_date) から :to (:to_date) までの差分', [
            'from' => $fromLink,
            'from_date' => e(\App\Support\Format\DateTimes::dateTime($fromChangeset->committed_on)),
            'to' => $toLink,
            'to_date' => e(\App\Support\Format\DateTimes::dateTime($toChangeset->committed_on)),
        ]) !!}
    </p>

    @if (trim($this->diff) === '')
        <p class="text-sm text-neutral-500">{{ __('このリビジョン間に差分はありません。') }}</p>
    @else
        @if ($this->shownDiff['truncated'])
            <p class="mb-2 text-sm text-warning-bold">{{ __('差分が大きいため、先頭:lines行だけを表示しています。', ['lines' => number_format(\App\Support\Scm\DisplayLimits::maxDiffLines())]) }}</p>
        @endif
        <pre class="overflow-x-auto rounded-md border border-neutral-200 bg-neutral-900 p-4 text-xs text-neutral-100">{{ $this->shownDiff['text'] }}</pre>
    @endif
</div>
