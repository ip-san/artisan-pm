<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public WikiPage $wikiPage;

    public function mount(Project $project, WikiPage $wikiPage): void
    {
        $this->authorize('view', $wikiPage);

        $this->project = $project;
        $this->wikiPage = $wikiPage->load(['parent', 'currentVersion.author']);
    }

    #[Computed]
    public function renderedContent(): string
    {
        return app(WikiMarkdownRenderer::class)->render($this->wikiPage->currentVersion?->text ?? '', $this->project);
    }

    /**
     * @return Collection<int, WikiPage>
     */
    #[Computed]
    public function children(): Collection
    {
        return $this->wikiPage->children()->orderBy('title')->get();
    }

    public function toggleProtected(): void
    {
        $this->authorize('protect', $this->wikiPage);

        $this->wikiPage->update(['is_protected' => ! $this->wikiPage->is_protected]);
    }

    public function toggleWatch(): void
    {
        $this->authorize('watch', $this->wikiPage);

        $existing = $this->wikiPage->watchers()->where('user_id', auth()->id())->first();

        if ($existing) {
            $existing->delete();
        } else {
            $this->wikiPage->watchers()->create(['user_id' => auth()->id()]);
        }

        $this->wikiPage->unsetRelation('watchers');
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->wikiPage);

        $this->wikiPage->delete();

        $this->redirect(route('wiki.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-3xl">
    @if ($wikiPage->parent)
        <p class="mb-2 text-sm text-gray-500">
            <a href="{{ route('wiki.show', [$project, $wikiPage->parent]) }}" class="text-indigo-600 hover:underline">
                {{ $wikiPage->parent->title }}
            </a>
        </p>
    @endif

    <div class="flex items-start justify-between mb-4">
        <h1 class="text-xl font-semibold text-gray-900">
            {{ $wikiPage->title }}
            @if ($wikiPage->is_protected)
                <span class="ml-1 text-xs text-gray-400">(保護)</span>
            @endif
        </h1>
        <div class="flex gap-2">
            @can('watch', $wikiPage)
                <button wire:click="toggleWatch" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $wikiPage->isWatchedBy(auth()->user()) ? 'ウォッチ解除' : 'ウォッチ' }}
                </button>
            @endcan
            <a href="{{ route('wiki.history', [$project, $wikiPage]) }}"
                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                履歴
            </a>
            @can('protect', $wikiPage)
                <button wire:click="toggleProtected" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $wikiPage->is_protected ? '保護解除' : '保護' }}
                </button>
            @endcan
            @can('update', $wikiPage)
                <a href="{{ route('wiki.edit', [$project, $wikiPage]) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    編集
                </a>
            @endcan
            @can('delete', $wikiPage)
                <button wire:click="delete" wire:confirm="このページを削除しますか?子ページは最上位に移動します。"
                    class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                    削除
                </button>
            @endcan
        </div>
    </div>

    <div class="prose prose-sm max-w-none rounded-md border border-gray-200 bg-white p-4">
        {!! $this->renderedContent !!}
    </div>

    @if ($wikiPage->currentVersion)
        <p class="mt-2 text-xs text-gray-500">
            最終更新: {{ $wikiPage->currentVersion->author->name }} — {{ $wikiPage->currentVersion->created_at->format('Y-m-d H:i') }}
            (v{{ $wikiPage->currentVersion->version }})
        </p>
    @endif

    @if ($this->children->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-2">子ページ</h2>
            <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
                @foreach ($this->children as $child)
                    <li wire:key="wiki-child-{{ $child->id }}" class="px-4 py-2">
                        <a href="{{ route('wiki.show', [$project, $child]) }}" class="text-indigo-600 hover:underline">
                            {{ $child->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
