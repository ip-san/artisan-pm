<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public WikiPage $wikiPage;

    public WikiPageVersion $wikiPageVersion;

    public function mount(Project $project, WikiPage $wikiPage, int $version): void
    {
        $this->authorize('viewHistory', $wikiPage);

        $this->project = $project;
        $this->wikiPage = $wikiPage;
        $this->wikiPageVersion = $wikiPage->versions()->where('version', $version)->with('author')->firstOrFail();
    }

    #[Computed]
    public function renderedContent(): string
    {
        return app(WikiMarkdownRenderer::class)->render($this->wikiPageVersion->text, $this->project, $this->wikiPage->attachments(), $this->wikiPage);
    }
}; ?>

<div class="max-w-3xl">
    <p class="mb-2 text-sm text-neutral-500">
        <a href="{{ route('wiki.show', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            {{ $wikiPage->title }}
        </a>
        —
        <a href="{{ route('wiki.history', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            履歴
        </a>
    </p>

    <div class="mb-4 flex items-center justify-between rounded-md border border-warning-subtler bg-warning-subtlest px-4 py-2 text-sm text-warning-bolder">
        <span>
            これは v{{ $wikiPageVersion->version }} の過去バージョンです
            ({{ $wikiPageVersion->author->displayName() }} — {{ $wikiPageVersion->created_at->format('Y-m-d H:i') }})。
        </span>
        @can('update', $wikiPage)
            <a href="{{ route('wiki.edit', [$project, $wikiPage]) }}?version={{ $wikiPageVersion->version }}"
                class="shrink-0 rounded-md border border-warning-subtle bg-white px-3 py-1 text-xs font-medium text-warning-bolder hover:bg-warning-subtler">
                このバージョンを復元
            </a>
        @endcan
    </div>

    <h1 class="text-xl font-semibold text-neutral-900 mb-4">{{ $wikiPage->title }}</h1>

    <div class="prose prose-sm max-w-none rounded-md border border-neutral-200 bg-white p-4">
        {!! $this->renderedContent !!}
    </div>
</div>
