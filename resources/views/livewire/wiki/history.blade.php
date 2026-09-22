<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use App\Services\WikiPageService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public WikiPage $wikiPage;

    public ?int $diffFrom = null;

    public ?int $diffTo = null;

    public function mount(Project $project, WikiPage $wikiPage): void
    {
        $this->authorize('viewHistory', $wikiPage);

        $this->project = $project;
        $this->wikiPage = $wikiPage;

        $versionNumbers = $this->versions->pluck('version')->sortDesc()->values();
        $this->diffTo = $versionNumbers->first();
        $this->diffFrom = $versionNumbers->get(1);
    }

    /**
     * @return Collection<int, WikiPageVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->wikiPage->versions()->with('author')->get();
    }

    /**
     * Whether the viewer may delete history at all: Redmine's
     * wiki#destroy_version needs delete_wiki_pages plus `editable?` (an
     * unprotected page, or protect_wiki_pages).
     */
    #[Computed]
    public function canDeleteVersions(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('delete', $this->wikiPage)
            && (! $this->wikiPage->is_protected || $user->can('protect', $this->wikiPage));
    }

    /**
     * Deletes one snapshot, like Redmine's wiki#destroy_version: any
     * version can go. Removing the current (highest-numbered) one simply
     * leaves the previous version as current, and removing the last
     * remaining one removes the whole page, since a page can't exist
     * without content.
     */
    public function deleteVersion(int $versionId): void
    {
        abort_unless($this->canDeleteVersions, 403);

        $version = $this->wikiPage->versions()->findOrFail($versionId);

        if ($this->wikiPage->versions()->count() <= 1) {
            app(WikiPageService::class)->delete($this->wikiPage);

            $this->redirect(route('wiki.index', $this->project), navigate: true);

            return;
        }

        $version->delete();

        unset($this->versions);
    }
}; ?>

<div class="max-w-2xl">
    <p class="mb-2 text-sm text-neutral-500">
        <a href="{{ route('wiki.show', [$project, $wikiPage]) }}" class="text-brand-bold hover:underline">
            {{ $wikiPage->title }}
        </a>
    </p>
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">履歴</h1>

    @php
        $versionCount = $this->versions->count();
        $maxVersion = $this->versions->max('version');
    @endphp

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @foreach ($this->versions as $version)
            <li wire:key="wiki-version-{{ $version->id }}" class="flex items-center justify-between px-4 py-2 text-sm">
                <div class="flex items-center gap-3">
                    @if ($versionCount > 1)
                        <span class="flex items-center gap-1 text-xs text-neutral-400">
                            <label class="flex items-center gap-0.5">
                                旧
                                <input type="radio" wire:model="diffFrom" value="{{ $version->version }}" class="border-neutral-300">
                            </label>
                            <label class="flex items-center gap-0.5">
                                新
                                <input type="radio" wire:model="diffTo" value="{{ $version->version }}" class="border-neutral-300">
                            </label>
                        </span>
                    @endif
                    <div>
                        <a href="{{ route('wiki.version', [$project, $wikiPage, $version->version]) }}" class="text-brand-bold hover:underline">
                            v{{ $version->version }}
                        </a>
                        <a href="{{ route('wiki.annotate', [$project, $wikiPage, $version->version]) }}" class="text-xs text-brand hover:underline">
                            (注釈)
                        </a>
                        <span class="text-neutral-500">— {{ $version->author->displayName() }} — {{ $version->created_at->format('Y-m-d H:i') }}</span>
                        @if ($version->comments)
                            <span class="text-neutral-400">({{ $version->comments }})</span>
                        @endif
                    </div>
                </div>
                @if ($this->canDeleteVersions)
                    <button wire:click="deleteVersion({{ $version->id }})"
                        wire:confirm="{{ $versionCount <= 1 ? 'これが最後のバージョンです。削除するとページ自体が削除されます。よろしいですか?' : ($version->version === $maxVersion ? '最新バージョンを削除すると、ひとつ前のバージョンが最新になります。よろしいですか?' : 'このバージョンを削除しますか?') }}"
                        class="shrink-0 text-xs font-medium text-danger-bolder hover:underline">
                        削除
                    </button>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($versionCount > 1)
        <div class="mt-4">
            @if ($diffFrom !== null && $diffTo !== null && $diffFrom !== $diffTo)
                <a href="{{ route('wiki.diff', [$project, $wikiPage, 'from' => $diffFrom, 'to' => $diffTo]) }}"
                    class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                    選択したバージョンを比較
                </a>
            @else
                <span class="text-xs text-neutral-400">比較する2つのバージョンを選択してください(旧/新)。</span>
            @endif
        </div>
    @endif
</div>
