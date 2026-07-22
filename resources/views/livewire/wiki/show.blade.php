<?php

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Markdown\WikiSectionEditLinkInjector;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public WikiPage $wikiPage;

    /** @var array<int, string> attachment media id => description input value */
    public array $attachmentDescriptions = [];

    public ?int $newWatcherId = null;

    public function mount(Project $project, WikiPage $wikiPage): void
    {
        $this->authorize('view', $wikiPage);

        $this->project = $project;
        $this->wikiPage = $wikiPage->load(['parent', 'currentVersion.author']);

        foreach ($this->wikiPage->attachments() as $media) {
            $this->attachmentDescriptions[$media->id] = (string) $media->getCustomProperty('description', '');
        }
    }

    /**
     * The page's raw Markdown source, matching Redmine's
     * WikiController#show format=txt (sends WikiContent#text verbatim,
     * no rendering).
     */
    public function exportTxt(): StreamedResponse
    {
        $this->authorize('export', $this->wikiPage);

        $text = $this->wikiPage->currentVersion?->text ?? '';

        return response()->streamDownload(
            fn () => print ($text),
            $this->exportFilename('txt'),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * The rendered HTML, wrapped in a minimal standalone document — same
     * "single exported page" scope as Redmine's format=html, which is
     * distinct from the wiki-wide multi-page export (WikiController#export,
     * PDF/ZIP included) that stays out of scope here.
     */
    public function exportHtml(): StreamedResponse
    {
        $this->authorize('export', $this->wikiPage);

        $body = app(WikiMarkdownRenderer::class)->render($this->wikiPage->currentVersion?->text ?? '', $this->project, $this->wikiPage->attachments());
        $title = e($this->wikiPage->title);

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="ja">
            <head>
            <meta charset="UTF-8">
            <title>{$title}</title>
            </head>
            <body>
            <h1>{$title}</h1>
            {$body}
            </body>
            </html>
            HTML;

        return response()->streamDownload(
            fn () => print ($html),
            $this->exportFilename('html'),
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function exportFilename(string $extension): string
    {
        return Str::of($this->wikiPage->title)->replace(['/', '\\'], '-')->append(".{$extension}")->toString();
    }

    #[Computed]
    public function renderedContent(): string
    {
        $html = app(WikiMarkdownRenderer::class)->render($this->wikiPage->currentVersion?->text ?? '', $this->project, $this->wikiPage->attachments());

        if (! auth()->user()?->can('update', $this->wikiPage)) {
            return $html;
        }

        return app(WikiSectionEditLinkInjector::class)->inject($html, route('wiki.edit', [$this->project, $this->wikiPage]));
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

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function watcherCandidates(): Collection
    {
        $watchingIds = $this->wikiPage->watchers->pluck('user_id');

        return $this->project->users->reject(fn (User $user) => $watchingIds->contains($user->id))->values();
    }

    public function addWatcher(): void
    {
        $this->authorize('manageWatchers', $this->wikiPage);

        $data = $this->validate([
            'newWatcherId' => ['required', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
        ]);

        $this->wikiPage->watchers()->firstOrCreate(['user_id' => $data['newWatcherId']]);

        $this->reset('newWatcherId');
        $this->wikiPage->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    public function removeWatcher(int $userId): void
    {
        $this->authorize('manageWatchers', $this->wikiPage);

        $this->wikiPage->watchers()->where('user_id', $userId)->delete();

        $this->wikiPage->unsetRelation('watchers');
        unset($this->watcherCandidates);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->wikiPage);

        app(WikiPageService::class)->delete($this->wikiPage);

        $this->redirect(route('wiki.index', $this->project), navigate: true);
    }

    public function deleteAttachment(int $mediaId): void
    {
        $this->authorize('update', $this->wikiPage);

        $this->wikiPage->attachments()->firstWhere('id', $mediaId)?->delete();
    }

    /**
     * Matches Redmine's Attachment#description — see the same feature on
     * issues.show for the reasoning behind reading from the bound array
     * rather than taking the value as a parameter.
     */
    public function updateAttachmentDescription(int $mediaId): void
    {
        $this->authorize('update', $this->wikiPage);

        $media = $this->wikiPage->attachments()->firstWhere('id', $mediaId);
        abort_if($media === null, 404);

        $description = trim((string) ($this->attachmentDescriptions[$mediaId] ?? ''));
        $media->setCustomProperty('description', $description !== '' ? $description : null);
        $media->save();
    }
}; ?>

<div class="flex items-start gap-6">
<div class="max-w-3xl flex-1">
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
            @can('export', $wikiPage)
                <button wire:click="exportTxt" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    TXT
                </button>
                <button wire:click="exportHtml" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    HTML
                </button>
            @endcan
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

    @if ($wikiPage->watchers->isNotEmpty() || auth()->user()?->can('manageWatchers', $wikiPage))
        <h2 class="mt-4 text-sm font-semibold text-gray-900 mb-2">ウォッチャー ({{ $wikiPage->watchers->count() }})</h2>
        <ul class="mb-3 flex flex-wrap gap-2">
            @foreach ($wikiPage->watchers as $watcher)
                <li class="flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">
                    {{ $watcher->user->name }}
                    @can('manageWatchers', $wikiPage)
                        <button wire:click="removeWatcher({{ $watcher->user_id }})" class="text-gray-400 hover:text-red-600" title="ウォッチャーから削除">×</button>
                    @endcan
                </li>
            @endforeach
        </ul>

        @can('manageWatchers', $wikiPage)
            @if ($this->watcherCandidates->isNotEmpty())
                <form wire:submit="addWatcher" class="mb-4 flex items-end gap-2">
                    <div>
                        <select wire:model="newWatcherId" class="block rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">ウォッチャーを追加...</option>
                            @foreach ($this->watcherCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        追加
                    </button>
                </form>
                @error('newWatcherId') <p class="-mt-2 mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
            @endif
        @endcan
    @endif

    @php $attachments = $wikiPage->attachments(); @endphp
    @if ($attachments->isNotEmpty())
        <h2 class="mt-4 text-sm font-semibold text-gray-900 mb-2">添付ファイル</h2>
        <ul class="mb-4 space-y-1">
            @foreach ($attachments as $media)
                <li class="py-1 text-sm" wire:key="wiki-attachment-{{ $media->id }}">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <x-attachment-thumbnail :media="$media" />
                            <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                                {{ $media->file_name }}
                            </a>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                            <x-download-count :media="$media" />
                            @can('update', $wikiPage)
                                <button wire:click="deleteAttachment({{ $media->id }})" wire:confirm="この添付ファイルを削除しますか?"
                                    class="text-red-600 hover:underline">削除</button>
                            @endcan
                        </span>
                    </div>
                    @can('update', $wikiPage)
                        <div class="mt-1 flex items-center gap-2">
                            <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                                class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                            <button wire:click="updateAttachmentDescription({{ $media->id }})"
                                class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                        </div>
                    @else
                        @if ($media->getCustomProperty('description'))
                            <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                        @endif
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

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

<x-wiki-sidebar :project="$project" />
</div>
