<?php

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use App\Support\Wiki\WikiAnnotator;
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

        $resolvedVersion = $wikiPage->versions()->where('version', $version)->with('author')->first();

        abort_if($resolvedVersion === null, 404);

        $this->project = $project;
        $this->wikiPage = $wikiPage;
        $this->wikiPageVersion = $resolvedVersion;
    }

    /**
     * @return list<array{version: int, author: ?User, text: string}>
     */
    #[Computed]
    public function lines(): array
    {
        return app(WikiAnnotator::class)->annotate($this->wikiPageVersion);
    }
}; ?>

<div class="max-w-4xl">
    <p class="mb-2 text-sm text-neutral-500">
        <a href="{{ route('wiki.show', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            {{ $wikiPage->title }}
        </a>
        —
        <a href="{{ route('wiki.history', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            履歴
        </a>
    </p>

    <h1 class="text-xl font-semibold text-neutral-900 mb-4">
        注釈: v{{ $wikiPageVersion->version }}
    </h1>

    <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white">
        <table class="min-w-full divide-y divide-neutral-200 font-mono text-sm">
            <tbody class="divide-y divide-neutral-100">
                @php $previous = null; @endphp
                @foreach ($this->lines as $index => $line)
                    <tr wire:key="annotate-line-{{ $index }}" class="align-top">
                        <td class="w-10 select-none px-2 py-0.5 text-right text-xs text-neutral-400">{{ $index + 1 }}</td>
                        <td class="w-14 px-2 py-0.5 text-xs text-neutral-500">
                            @if ($previous === null || $previous['version'] !== $line['version'])
                                <a href="{{ route('wiki.version', [$project, $wikiPage, $line['version']]) }}" class="text-brand-bold hover:underline">
                                    v{{ $line['version'] }}
                                </a>
                            @endif
                        </td>
                        <td class="w-32 truncate px-2 py-0.5 text-xs text-neutral-500">
                            @if ($previous === null || $previous['version'] !== $line['version'] || $previous['author']?->id !== $line['author']?->id)
                                {{ $line['author']?->name ?? '(不明)' }}
                            @endif
                        </td>
                        <td class="whitespace-pre px-2 py-0.5">{{ $line['text'] }}</td>
                    </tr>
                    @php $previous = $line; @endphp
                @endforeach
            </tbody>
        </table>
    </div>
</div>
