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

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    #[Url]
    public string $query = '';

    #[Url]
    public bool $allWords = true;

    #[Url]
    public bool $titlesOnly = false;

    #[Url]
    public bool $openIssuesOnly = false;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;

        $this->jumpToIssueIfIdQuery();
    }

    /**
     * @return Collection<int, SearchResult>
     */
    #[Computed]
    public function results(): Collection
    {
        return app(SearchService::class)->search(
            $this->project,
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
     * running a text search — matches Redmine's SearchController, which
     * redirects when the query is an issue-id reference. Falls through
     * to a normal search when the issue doesn't exist here or isn't
     * visible, so no information leaks about other projects' ids.
     */
    private function jumpToIssueIfIdQuery(): void
    {
        if (preg_match('/^#?(\d+)$/', trim($this->query), $matches) !== 1) {
            return;
        }

        $issue = Issue::query()->where('project_id', $this->project->id)->find((int) $matches[1]);

        if ($issue === null || auth()->user()?->cannot('view', $issue)) {
            return;
        }

        $this->redirect(route('issues.show', [$this->project, $issue]), navigate: true);
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
    ];
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — 検索</h1>

    <form wire:submit="search" class="mb-6 space-y-3">
        <div class="flex gap-2">
            <input type="text" wire:model="query" placeholder="検索キーワード(#123 で課題へジャンプ)"
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
