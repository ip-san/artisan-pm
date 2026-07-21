<?php

use App\Enums\VersionStatus;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?Version $version = null;

    public string $name = '';

    public string $description = '';

    public string $status = 'open';

    public ?string $due_date = null;

    public function mount(Project $project, ?Version $version = null): void
    {
        $this->project = $project;

        if ($version?->exists) {
            abort_unless($version->project_id === $project->id, 404);

            $this->authorize('update', $version);

            $this->version = $version;
            $this->name = $version->name;
            $this->description = (string) $version->description;
            $this->status = $version->status->value;
            $this->due_date = $version->due_date?->toDateString();
        } else {
            $this->authorize('create', [Version::class, $project]);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('versions', 'name')->where('project_id', $this->project->id)->ignore($this->version?->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(array_map(fn (VersionStatus $s) => $s->value, VersionStatus::cases()))],
            'due_date' => ['nullable', 'date'],
        ]);

        $data['project_id'] = $this->project->id;

        if ($this->version) {
            $this->version->update($data);
        } else {
            Version::create($data);
        }

        $this->redirect(route('versions.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $version ? 'バージョンを編集' : '新規バージョン' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">ステータス</label>
            <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="open">オープン</option>
                <option value="locked">ロック中</option>
                <option value="closed">クローズ</option>
            </select>
            @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">期日</label>
            <input type="date" wire:model="due_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('due_date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('versions.index', $project) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
