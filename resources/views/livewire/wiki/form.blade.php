<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?WikiPage $wikiPage = null;

    public string $title = '';

    public ?int $parent_id = null;

    public bool $is_protected = false;

    public string $text = '';

    public string $comments = '';

    public function mount(Project $project, ?WikiPage $wikiPage = null): void
    {
        $this->project = $project;

        if ($wikiPage?->exists) {
            $this->authorize('update', $wikiPage);

            $this->wikiPage = $wikiPage;
            $this->title = $wikiPage->title;
            $this->parent_id = $wikiPage->parent_id;
            $this->is_protected = $wikiPage->is_protected;
            $this->text = $wikiPage->currentVersion?->text ?? '';
        } else {
            $this->authorize('create', [WikiPage::class, $project]);

            $this->title = (string) request()->query('title', '');
        }
    }

    /**
     * Root-level candidates for the parent picker — excludes the page
     * being edited itself and its own descendants, which would otherwise
     * let a page become its own ancestor.
     *
     * @return Collection<int, WikiPage>
     */
    #[Computed]
    public function availableParents(): Collection
    {
        $pages = $this->project->wikiPages()->orderBy('title')->get();

        if ($this->wikiPage === null) {
            return $pages;
        }

        $excluded = collect([$this->wikiPage->id]);
        $frontier = $excluded;

        while ($frontier->isNotEmpty()) {
            $children = $pages->whereIn('parent_id', $frontier)->pluck('id');
            $frontier = $children->diff($excluded);
            $excluded = $excluded->merge($children);
        }

        return $pages->reject(fn (WikiPage $page) => $excluded->contains($page->id))->values();
    }

    #[Computed]
    public function canRename(): bool
    {
        return $this->wikiPage === null || app(AuthorizationService::class)->can(auth()->user(), 'rename_wiki_pages', $this->project);
    }

    #[Computed]
    public function canProtect(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'protect_wiki_pages', $this->project);
    }

    public function save(): void
    {
        $rules = [
            'text' => ['required', 'string'],
            'comments' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->canRename) {
            $rules['title'] = [
                'required', 'string', 'max:255',
                Rule::unique('wiki_pages', 'title')->where('project_id', $this->project->id)->ignore($this->wikiPage),
            ];
            $rules['parent_id'] = [
                'nullable',
                Rule::exists('wiki_pages', 'id')->where('project_id', $this->project->id),
                Rule::in($this->availableParents->pluck('id')->push(null)->all()),
            ];
        }

        if ($this->canProtect) {
            $rules['is_protected'] = ['boolean'];
        }

        $data = $this->validate($rules);
        $text = $data['text'];
        $comments = $data['comments'] ?? null;
        unset($data['text'], $data['comments']);

        $service = app(WikiPageService::class);

        if ($this->wikiPage) {
            $page = $service->update($this->wikiPage, $data, $text, auth()->user(), $comments ?: null);
        } else {
            $page = $service->create($this->project, $data, $text, auth()->user());
        }

        $this->redirect(route('wiki.show', [$this->project, $page]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $wikiPage ? "「{$wikiPage->title}」を編集" : '新規Wikiページ' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        @if ($this->canRename)
            <div>
                <label class="block text-sm font-medium text-gray-700">タイトル</label>
                <input type="text" wire:model="title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">親ページ</label>
                <select wire:model="parent_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">なし</option>
                    @foreach ($this->availableParents as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->title }}</option>
                    @endforeach
                </select>
                @error('parent_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @else
            <p class="text-sm text-gray-500">タイトル: {{ $title }}</p>
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700">本文(Markdown)</label>
            <textarea wire:model="text" rows="16" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm"></textarea>
            @error('text') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-gray-500">
                「#123」で課題にリンク、「[[ページ名]]」または「[[ページ名|表示名]]」で他のWikiページにリンクできます。
            </p>
        </div>

        @if ($wikiPage)
            <div>
                <label class="block text-sm font-medium text-gray-700">コメント(任意)</label>
                <input type="text" wire:model="comments" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            </div>
        @endif

        @if ($this->canProtect)
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="is_protected" class="rounded border-gray-300">
                保護する(編集にはページ保護権限が必要になります)
            </label>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $wikiPage ? route('wiki.show', [$project, $wikiPage]) : route('wiki.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
