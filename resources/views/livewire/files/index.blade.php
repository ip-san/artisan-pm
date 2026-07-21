<?php

use App\Models\Project;
use App\Models\Version;
use App\Support\Attachments\AttachmentValidationRules;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?int $version_id = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newFiles = [];

    /**
     * Matches Redmine's FilesController#index sort_clause, applied the
     * same way across every container (the project's own files and each
     * version's) rather than per-list.
     */
    public string $sortBy = 'filename';

    public string $sortDirection = 'asc';

    /** @var array<int, string> attachment media id => description input value */
    public array $attachmentDescriptions = [];

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Version::class, $project]);

        $this->project = $project;
        $this->version_id = $this->versions->first()?->id;

        $allFiles = $this->project->files()->concat($this->versions->flatMap(fn (Version $version) => $version->files()));

        foreach ($allFiles as $media) {
            $this->attachmentDescriptions[$media->id] = (string) $media->getCustomProperty('description', '');
        }
    }

    public function sortFiles(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * @param  MediaCollection<int, Media>  $files
     * @return Collection<int, Media>
     */
    public function sortedFiles(MediaCollection $files): Collection
    {
        $sorted = match ($this->sortBy) {
            'created_on' => $files->sortBy('created_at'),
            'size' => $files->sortBy('size'),
            'downloads' => $files->sortBy(fn (Media $media) => (int) $media->getCustomProperty('download_count', 0)),
            default => $files->sortBy('file_name'),
        };

        return $this->sortDirection === 'desc' ? $sorted->reverse()->values() : $sorted->values();
    }

    /**
     * @return Collection<int, Version>
     */
    #[Computed]
    public function versions(): Collection
    {
        return $this->project->versions()->orderByDesc('due_date')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'manage_files', $this->project);
    }

    public function upload(): void
    {
        $this->authorize('viewAny', [Version::class, $this->project]);

        $data = $this->validate([
            'version_id' => ['nullable', Rule::exists('versions', 'id')->where('project_id', $this->project->id)],
            'newFiles' => ['required', 'array', 'min:1'],
            'newFiles.*' => AttachmentValidationRules::rules(),
        ]);

        $target = $data['version_id'] === null ? $this->project : $this->project->versions()->findOrFail($data['version_id']);

        $this->authorize('manageFiles', $target);

        foreach ($this->newFiles as $file) {
            $target->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('files');
        }

        $this->reset('newFiles');
        unset($this->versions);
        $this->project->unsetRelation('media');
    }

    /**
     * Matches Redmine's Attachment#description — see the same feature on
     * issues.show for the reasoning behind reading from the bound array
     * rather than taking the value as a parameter. The target (project or
     * one of its versions) is derived from the media's own polymorphic
     * owner rather than trusted from the client, and re-scoped to this
     * project so a crafted id can't touch another project's file.
     */
    public function updateAttachmentDescription(int $mediaId): void
    {
        $media = Media::query()->find($mediaId);
        abort_if($media === null, 404);

        $target = $media->model;
        abort_unless($target instanceof Project || $target instanceof Version, 404);

        $belongsToThisProject = $target instanceof Project
            ? $target->is($this->project)
            : $target->project_id === $this->project->id;

        abort_unless($belongsToThisProject, 404);

        $this->authorize('manageFiles', $target);

        $description = trim((string) ($this->attachmentDescriptions[$mediaId] ?? ''));
        $media->setCustomProperty('description', $description !== '' ? $description : null);
        $media->save();
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — ファイル</h1>

    @if ($this->canManage)
        <form wire:submit="upload" class="mb-6 flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-white p-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">バージョン</label>
                <select wire:model="version_id" class="mt-1 block rounded-md border-gray-300 text-sm">
                    <option value="">プロジェクト全体(バージョンなし)</option>
                    @foreach ($this->versions as $version)
                        <option value="{{ $version->id }}">{{ $version->name }}</option>
                    @endforeach
                </select>
                @error('version_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">ファイル</label>
                <input type="file" wire:model="newFiles" multiple class="mt-1 block text-sm text-gray-700">
                @error('newFiles.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('newFiles') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                アップロード
            </button>
        </form>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-4 text-xs text-gray-500">
        <span>並び替え:</span>
        @foreach (['filename' => 'ファイル名', 'created_on' => '日付', 'size' => 'サイズ', 'downloads' => 'ダウンロード数'] as $key => $label)
            <button type="button" wire:click="sortFiles('{{ $key }}')"
                class="hover:underline {{ $sortBy === $key ? 'font-semibold text-gray-900' : '' }}">
                {{ $label }}
                @if ($sortBy === $key)
                    {{ $sortDirection === 'asc' ? '▲' : '▼' }}
                @endif
            </button>
        @endforeach
    </div>

    @if ($this->project->files()->isNotEmpty() || $this->canManage)
        <div class="mb-4 overflow-hidden rounded-md border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2">
                <span class="text-sm font-medium text-gray-900">プロジェクト全体(バージョンなし)</span>
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse ($this->sortedFiles($this->project->files()) as $media)
                    <li class="px-4 py-2 text-sm" wire:key="project-file-{{ $media->id }}">
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
                            </span>
                        </div>
                        @if ($this->canManage)
                            <div class="mt-1 flex items-center gap-2">
                                <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                                    class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                                <button wire:click="updateAttachmentDescription({{ $media->id }})"
                                    class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                            </div>
                        @elseif ($media->getCustomProperty('description'))
                            <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-gray-500">ファイルはありません。</li>
                @endforelse
            </ul>
        </div>
    @endif

    @forelse ($this->versions as $version)
        <div wire:key="version-{{ $version->id }}" class="mb-4 overflow-hidden rounded-md border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2">
                <span class="text-sm font-medium text-gray-900">{{ $version->name }}</span>
                @if ($version->due_date)
                    <span class="ml-2 text-xs text-gray-500">期日: {{ $version->due_date->toDateString() }}</span>
                @endif
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse ($this->sortedFiles($version->files()) as $media)
                    <li class="px-4 py-2 text-sm" wire:key="version-file-{{ $media->id }}">
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
                            </span>
                        </div>
                        @if ($this->canManage)
                            <div class="mt-1 flex items-center gap-2">
                                <input type="text" wire:model="attachmentDescriptions.{{ $media->id }}" placeholder="説明(任意)"
                                    class="block w-full rounded-md border-gray-300 text-xs shadow-sm">
                                <button wire:click="updateAttachmentDescription({{ $media->id }})"
                                    class="shrink-0 text-xs text-indigo-600 hover:underline">保存</button>
                            </div>
                        @elseif ($media->getCustomProperty('description'))
                            <p class="mt-1 text-xs text-gray-500">{{ $media->getCustomProperty('description') }}</p>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-gray-500">ファイルはありません。</li>
                @endforelse
            </ul>
        </div>
    @empty
        <p class="text-sm text-gray-500">バージョンがありません。</p>
    @endforelse
</div>
