<?php

use App\Enums\RepositoryType;
use App\Models\Project;
use App\Models\Repository;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?Repository $repository = null;

    public string $type = 'git';

    public string $path = '';

    public function mount(Project $project): void
    {
        $this->authorize('manage', [Repository::class, $project]);

        $this->project = $project;
        $this->repository = $project->repository;

        if ($this->repository) {
            $this->type = $this->repository->type->value;
            $this->path = $this->repository->path;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'type' => ['required', 'in:git'],
            'path' => ['required', 'string', 'max:500'],
        ]);

        if ($this->repository) {
            $this->repository->update($data);
        } else {
            $data['project_id'] = $this->project->id;
            Repository::create($data);
        }

        $this->redirect(route('repository.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">リポジトリ設定</h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">種別</label>
            <select wire:model="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @foreach (RepositoryType::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->value }}</option>
                @endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">パス</label>
            <input type="text" wire:model="path" placeholder="/path/to/repo.git"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('path') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-gray-500">
                アプリケーションサーバーからアクセス可能なローカルパスを指定してください。
            </p>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('repository.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
