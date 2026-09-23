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
        $this->authorize('viewHistory', $wikiPage);

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
    <p class="mb-2 text-sm text-neutral-500">
        <a href="{{ route('wiki.show', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            {{ $wikiPage->title }}
        </a>
        —
        <a href="{{ route('wiki.history', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            {{ __('履歴') }}
        </a>
    </p>

    <h1 class="text-xl font-semibold text-neutral-900 mb-4">
        {{ __('差分: v:old_version → v:new_version', ['old_version' => $versionFrom->version, 'new_version' => $versionTo->version]) }}
    </h1>

    <p class="mb-4 text-xs text-neutral-500">
        {{ __('v:old_version (:old_author — :old_date) から v:new_version (:new_author — :new_date) への変更', [
            'old_version' => $versionFrom->version,
            'old_author' => $versionFrom->author->displayName(),
            'old_date' => $versionFrom->created_at->format('Y-m-d H:i'),
            'new_version' => $versionTo->version,
            'new_author' => $versionTo->author->displayName(),
            'new_date' => $versionTo->created_at->format('Y-m-d H:i'),
        ]) }}
    </p>

    <div class="whitespace-pre-wrap break-words rounded-md border border-neutral-200 bg-white p-4 font-mono text-sm leading-relaxed">
        @foreach ($this->diff as $chunk)
            @if ($chunk['type'] === 'add')
                <ins class="bg-success-subtler text-success-bolder no-underline">{{ $chunk['text'] }}</ins>
            @elseif ($chunk['type'] === 'del')
                <del class="bg-danger-subtler text-danger-boldest">{{ $chunk['text'] }}</del>
            @else
                {{ $chunk['text'] }}
            @endif
        @endforeach
    </div>
</div>
