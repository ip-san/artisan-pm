<?php

use App\Models\Project;
use App\Models\Version;
use App\Support\Authorization\AuthorizationService;
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

    public ?int $version_id = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newFiles = [];

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [Version::class, $project]);

        $this->project = $project;
        $this->version_id = $this->versions->first()?->id;
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
            'version_id' => ['required', Rule::exists('versions', 'id')->where('project_id', $this->project->id)],
            'newFiles' => ['required', 'array', 'min:1'],
            'newFiles.*' => ['file', 'max:'.intdiv(config('media-library.max_file_size'), 1024)],
        ]);

        $version = $this->project->versions()->findOrFail($data['version_id']);

        $this->authorize('manageFiles', $version);

        foreach ($this->newFiles as $file) {
            $version->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('files');
        }

        $this->reset('newFiles');
        unset($this->versions);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — ファイル</h1>

    @if ($this->canManage)
        <form wire:submit="upload" class="mb-6 flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-white p-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">バージョン</label>
                <select wire:model="version_id" class="mt-1 block rounded-md border-gray-300 text-sm">
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

    @forelse ($this->versions as $version)
        <div wire:key="version-{{ $version->id }}" class="mb-4 overflow-hidden rounded-md border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2">
                <span class="text-sm font-medium text-gray-900">{{ $version->name }}</span>
                @if ($version->due_date)
                    <span class="ml-2 text-xs text-gray-500">期日: {{ $version->due_date->toDateString() }}</span>
                @endif
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse ($version->files() as $media)
                    <li class="flex items-center justify-between px-4 py-2 text-sm">
                        <a href="{{ route('attachments.show', $media) }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">
                            {{ $media->file_name }}
                        </a>
                        <span class="flex items-center gap-2">
                            <span class="text-gray-500">{{ $media->human_readable_size }}</span>
                            <x-download-count :media="$media" />
                        </span>
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
