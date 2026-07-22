<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use App\Support\Attachments\AttachmentValidationRules;
use App\Support\Authorization\AuthorizationService;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Markdown\WikiSectionSplitter;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?WikiPage $wikiPage = null;

    public string $title = '';

    public ?int $parent_id = null;

    public bool $is_protected = false;

    public string $text = '';

    public string $comments = '';

    public bool $redirectExistingLinks = true;

    public ?int $sectionIndex = null;

    public ?string $sectionHash = null;

    public bool $showPreview = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    public function mount(Project $project, ?WikiPage $wikiPage = null): void
    {
        $this->project = $project;

        if ($wikiPage?->exists) {
            $this->authorize('update', $wikiPage);

            $this->wikiPage = $wikiPage;
            $this->title = $wikiPage->title;
            $this->parent_id = $wikiPage->parent_id;
            $this->is_protected = $wikiPage->is_protected;

            // A `?version=` query string (arrived at via a past version's
            // "このバージョンを復元" link) prefills the editor with that
            // older text instead of the current one — matching Redmine's
            // WikiController#edit + content_for_version, where "reverting"
            // isn't a distinct action but simply editing an old version's
            // text and saving it as a new version.
            $requestedVersion = request()->integer('version') ?: null;
            $sourceVersion = $requestedVersion !== null
                ? $wikiPage->versions()->where('version', $requestedVersion)->first()
                : null;

            $fullText = ($sourceVersion ?? $wikiPage->currentVersion)?->text ?? '';

            // Section editing (via a "編集" link on a rendered heading) only
            // applies to the current text, never to an old version being
            // restored — the two query parameters are mutually exclusive.
            $requestedSection = $requestedVersion === null ? (request()->integer('section') ?: null) : null;
            $section = $requestedSection !== null
                ? app(WikiSectionSplitter::class)->extractSections($fullText, $requestedSection)[1]
                : '';

            if ($requestedSection !== null && $section !== '') {
                $this->sectionIndex = $requestedSection;
                $this->sectionHash = hash('sha256', $section);
                $this->text = $section;
            } else {
                $this->text = $fullText;
            }
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

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }

    /**
     * Matches Redmine's wiki edit form "Preview" button — renders the
     * textarea's current content without saving. Inline image references
     * resolve against the page's already-uploaded attachments only; files
     * selected in this same submission aren't Media records yet.
     */
    #[Computed]
    public function previewHtml(): string
    {
        return app(WikiMarkdownRenderer::class)->render($this->text, $this->project, $this->wikiPage?->attachments());
    }

    /**
     * Splices the edited section text back into a fresh read of the full
     * page, after checking the section hasn't changed since it was loaded
     * into this form — matches Redmine's StaleSectionError, which refuses
     * to silently overwrite a concurrent edit to the same section.
     */
    private function spliceSection(string $replacement): ?string
    {
        /** @var WikiPage $wikiPage */
        $wikiPage = $this->wikiPage;
        $currentText = $wikiPage->fresh('currentVersion')?->currentVersion?->text ?? '';

        $splitter = app(WikiSectionSplitter::class);
        $currentSection = $splitter->extractSections($currentText, $this->sectionIndex)[1];

        if (hash('sha256', $currentSection) !== $this->sectionHash) {
            $this->addError('text', 'このセクションは他のユーザーによって更新されているため保存できません。ページを再読み込みしてください。');

            return null;
        }

        return $splitter->updateSection($currentText, $this->sectionIndex, $replacement)['text'];
    }

    public function save(): void
    {
        $rules = [
            'text' => ['required', 'string'],
            'comments' => ['nullable', 'string', 'max:255'],
            'newAttachments.*' => AttachmentValidationRules::rules(),
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
        unset($data['text'], $data['comments'], $data['newAttachments']);

        $service = app(WikiPageService::class);

        if ($this->wikiPage) {
            if ($this->sectionIndex !== null) {
                $text = $this->spliceSection($text);

                if ($text === null) {
                    return;
                }
            }

            $page = $service->update($this->wikiPage, $data, $text, auth()->user(), $comments ?: null, $this->redirectExistingLinks);
        } else {
            $page = $service->create($this->project, $data, $text, auth()->user());
        }

        foreach ($this->newAttachments as $file) {
            $page->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('attachments');
        }

        $this->redirect(route('wiki.show', [$this->project, $page]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $wikiPage ? "「{$wikiPage->title}」を編集" : '新規Wikiページ' }}
    </h1>

    @if ($sectionIndex !== null)
        <p class="mb-4 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-700">
            このセクションのみを編集しています。保存すると、このセクション以外の本文はそのまま維持されます。
        </p>
    @endif

    <form wire:submit="save" class="space-y-4">
        @if ($this->canRename)
            <div>
                <label class="block text-sm font-medium text-gray-700">タイトル</label>
                <input type="text" wire:model="title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($wikiPage)
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="redirectExistingLinks" class="rounded border-gray-300">
                    タイトルを変更した場合、旧タイトルへのリンクをリダイレクトする
                </label>
            @endif

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
            <button type="button" wire:click="togglePreview"
                class="mt-2 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                {{ $showPreview ? 'プレビューを閉じる' : 'プレビュー' }}
            </button>

            @if ($showPreview)
                <div class="prose prose-sm mt-2 max-w-none rounded-md border border-gray-200 bg-gray-50 p-4">
                    @if (trim($text) === '')
                        <p class="text-sm text-gray-400">(本文が空です)</p>
                    @else
                        {!! $this->previewHtml !!}
                    @endif
                </div>
            @endif
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

        <div>
            <label class="block text-sm font-medium text-gray-700">添付ファイル</label>
            <input type="file" wire:model="newAttachments" multiple
                class="mt-1 block w-full text-sm text-gray-700">
            @error('newAttachments.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            @if ($wikiPage?->attachments()->isNotEmpty())
                <ul class="mt-2 space-y-1">
                    @foreach ($wikiPage->attachments() as $media)
                        <li class="text-sm text-gray-600">{{ $media->file_name }} ({{ $media->human_readable_size }})</li>
                    @endforeach
                </ul>
            @endif
        </div>

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
