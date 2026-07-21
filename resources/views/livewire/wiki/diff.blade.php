<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use App\Support\Diff\WordDiffer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public WikiPage $wikiPage;

    public WikiPageVersion $versionFrom;

    public WikiPageVersion $versionTo;

    /**
     * Matches Redmine's WikiPage#diff: whichever of the two version
     * numbers is actually earlier becomes "from", regardless of the order
     * they arrived in the URL — a diff link doesn't have to be built with
     * the older version first.
     */
    public function mount(Project $project, WikiPage $wikiPage, int $from, int $to): void
    {
        $this->authorize('view', $wikiPage);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $versionFrom = $wikiPage->versions()->where('version', $from)->with('author')->first();
        $versionTo = $wikiPage->versions()->where('version', $to)->with('author')->first();

        abort_if($versionFrom === null || $versionTo === null, 404);

        $this->project = $project;
        $this->wikiPage = $wikiPage;
        $this->versionFrom = $versionFrom;
        $this->versionTo = $versionTo;
    }

    /**
     * @return list<array{type: 'same'|'add'|'del', text: string}>
     */
    #[Computed]
    public function diff(): array
    {
        return app(WordDiffer::class)->diff($this->versionFrom->text, $this->versionTo->text);
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

    <h1 class="text-xl font-semibold text-gray-900 mb-4">
        差分: v{{ $versionFrom->version }} → v{{ $versionTo->version }}
    </h1>

    <p class="mb-4 text-xs text-gray-500">
        v{{ $versionFrom->version }} ({{ $versionFrom->author->name }} — {{ $versionFrom->created_at->format('Y-m-d H:i') }})
        から
        v{{ $versionTo->version }} ({{ $versionTo->author->name }} — {{ $versionTo->created_at->format('Y-m-d H:i') }})
        への変更
    </p>

    <div class="whitespace-pre-wrap break-words rounded-md border border-gray-200 bg-white p-4 font-mono text-sm leading-relaxed">
        @foreach ($this->diff as $chunk)
            @if ($chunk['type'] === 'add')
                <ins class="bg-green-100 text-green-800 no-underline">{{ $chunk['text'] }}</ins>
            @elseif ($chunk['type'] === 'del')
                <del class="bg-red-100 text-red-800">{{ $chunk['text'] }}</del>
            @else
                {{ $chunk['text'] }}
            @endif
        @endforeach
    </div>
</div>
