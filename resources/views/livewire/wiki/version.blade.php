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
        $this->authorize('view', $wikiPage);

        $this->project = $project;
        $this->wikiPage = $wikiPage;
        $this->wikiPageVersion = $wikiPage->versions()->where('version', $version)->with('author')->firstOrFail();
    }

    #[Computed]
    public function renderedContent(): string
    {
        return app(WikiMarkdownRenderer::class)->render($this->wikiPageVersion->text, $this->project);
    }
}; ?>

<div class="max-w-3xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('wiki.show', [$project, $wikiPage]) }}" class="text-indigo-600 hover:underline">
            {{ $wikiPage->title }}
        </a>
        —
        <a href="{{ route('wiki.history', [$project, $wikiPage]) }}" class="text-indigo-600 hover:underline">
            履歴
        </a>
    </p>

    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
        これは v{{ $wikiPageVersion->version }} の過去バージョンです
        ({{ $wikiPageVersion->author->name }} — {{ $wikiPageVersion->created_at->format('Y-m-d H:i') }})。
    </div>

    <h1 class="text-xl font-semibold text-gray-900 mb-4">{{ $wikiPage->title }}</h1>

    <div class="prose prose-sm max-w-none rounded-md border border-gray-200 bg-white p-4">
        {!! $this->renderedContent !!}
    </div>
</div>
