<?php

use App\Models\Issue;
use App\Models\Project;
use App\Services\SearchService;
use App\Support\Search\SearchResult;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Redmine's actual default search behavior — site-wide, not scoped to one
 * project. search.index (the project-scoped page, kept unchanged) remains
 * the narrower "search within this project" variant reachable from inside
 * a project, matching how Redmine's own search form narrows once you're
 * inside a project rather than replacing the top-level search entirely.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    #[Url]
    public string $query = '';

    #[Url]
    public bool $allWords = true;

    #[Url]
    public bool $titlesOnly = false;

    #[Url]
    public bool $openIssuesOnly = false;

    #[Url]
    public bool $myProjectsOnly = false;

    public function mount(): void
    {
        $this->jumpToIssueIfIdQuery();
    }

    /**
     * Every project the viewer can see at all — SearchService further
     * narrows this per searchable type (view_issues, view_wiki_pages, ...)
     * internally, same as issues.global-index/time-entries.global-index
     * resolve their own visible-project sets.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function visibleProjects(): Collection
    {
        return Project::query()
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project))
            ->values();
    }

    /**
     * Matches Redmine's scope=my_projects (User.current.projects — every
     * project the viewer actually holds membership in, narrower than
     * "publicly visible"), further intersected with visibleProjects() in
     * case membership alone wouldn't already imply visibility.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function searchableProjects(): Collection
    {
        if (! $this->myProjectsOnly || ! auth()->check()) {
            return $this->visibleProjects;
        }

        $memberProjectIds = auth()->user()->projects()->pluck('projects.id');

        return $this->visibleProjects->whereIn('id', $memberProjectIds)->values();
    }

    /**
     * @return Collection<int, SearchResult>
     */
    #[Computed]
    public function results(): Collection
    {
        return app(SearchService::class)->searchAcrossProjects(
            $this->searchableProjects,
            auth()->user(),
            $this->query,
            allWords: $this->allWords,
            titlesOnly: $this->titlesOnly,
            openIssuesOnly: $this->openIssuesOnly,
        );
    }

    public function search(): void
    {
        $this->jumpToIssueIfIdQuery();

        unset($this->results);
    }

    /**
     * "#123" (or a bare "123") jumps straight to that issue instead of
     * running a text search — matches search.index's own shortcut, but
     * issue ids are globally unique here so no project scoping is needed
     * to look one up; the visibility check still applies before jumping.
     */
    private function jumpToIssueIfIdQuery(): void
    {
        if (preg_match('/^#?(\d+)$/', trim($this->query), $matches) !== 1) {
            return;
        }

        $issue = Issue::query()->find((int) $matches[1]);

        if ($issue === null || auth()->user()?->cannot('view', $issue)) {
            return;
        }

        $this->redirect(route('issues.show', [$issue->project, $issue]), navigate: true);
    }

    /**
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        'issue' => '課題',
        'wiki-page' => 'Wiki',
        'news' => 'お知らせ',
        'document' => '文書',
        'message' => 'フォーラム',
        'changeset' => 'リポジトリ',
        'project' => 'プロジェクト',
    ];
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">検索(全プロジェクト)</h1>

    <form wire:submit="search" class="mb-6 space-y-3">
        <div class="flex gap-2">
            <input type="text" wire:model="query" placeholder="検索キーワード"
                class="block w-full max-w-md rounded-md border-gray-300 shadow-sm sm:text-sm">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                検索
            </button>
        </div>
        <div class="flex flex-wrap gap-4 text-sm text-gray-700">
            <label class="flex items-center gap-1.5">
                <input type="checkbox" wire:model="allWords" class="rounded border-gray-300">
                すべての単語を含む
            </label>
            <label class="flex items-center gap-1.5">
                <input type="checkbox" wire:model="titlesOnly" class="rounded border-gray-300">
                タイトルのみ
            </label>
            <label class="flex items-center gap-1.5">
                <input type="checkbox" wire:model="openIssuesOnly" class="rounded border-gray-300">
                オープンな課題のみ
            </label>
            <label class="flex items-center gap-1.5">
                <input type="checkbox" wire:model="myProjectsOnly" class="rounded border-gray-300">
                自分のプロジェクトのみ
            </label>
        </div>
    </form>

    @if (trim($query) === '')
        <p class="text-sm text-gray-500">検索キーワードを入力してください。</p>
    @elseif ($this->results->isEmpty())
        <p class="text-sm text-gray-500">「{{ $query }}」に一致する結果が見つかりませんでした。</p>
    @else
        <p class="mb-4 text-sm text-gray-500">{{ $this->results->count() }}件の結果</p>
        <ul class="space-y-3">
            @foreach ($this->results as $result)
                <li wire:key="result-{{ $result->type }}-{{ $result->url }}" class="rounded-md border border-gray-200 bg-white p-4">
                    <span class="mr-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                        {{ self::TYPE_LABELS[$result->type] ?? $result->type }}
                    </span>
                    <a href="{{ $result->url }}" class="font-medium text-indigo-600 hover:underline">{{ $result->title }}</a>
                    @if ($result->excerpt)
                        <p class="mt-1 text-sm text-gray-600">{{ $result->excerpt }}</p>
                    @endif
                    <p class="mt-1 text-xs text-gray-400">{{ $result->updatedAt->format('Y-m-d H:i') }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
