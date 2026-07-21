<?php

use App\Enums\ProjectModuleKey;
use App\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Project $project = null;

    public string $name = '';

    public string $identifier = '';

    public string $description = '';

    public bool $is_public = true;

    /** @var array<string> */
    public array $modules = [];

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            $this->authorize('update', $project);

            $this->project = $project;
            $this->name = $project->name;
            $this->identifier = $project->identifier;
            $this->description = (string) $project->description;
            $this->is_public = $project->is_public;
            $this->modules = $project->moduleAssignments->pluck('module.value')->all();
        } else {
            $this->authorize('create', Project::class);

            $this->modules = array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults());
        }
    }

    public function updatedName(string $value): void
    {
        if (! $this->project) {
            $this->identifier = Str::slug($value);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'identifier' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('projects', 'identifier')->ignore($this->project?->id),
            ],
            'description' => ['nullable', 'string'],
            'is_public' => ['boolean'],
        ]);

        if ($this->project) {
            $this->project->update($data);
        } else {
            $this->project = Project::create($data);
        }

        $this->project->syncModules(
            collect($this->modules)->map(fn (string $m) => ProjectModuleKey::from($m))->all()
        );

        $this->redirect(route('projects.show', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $project ? 'プロジェクトを編集' : '新規プロジェクト' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model.live.debounce.400ms="name"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">識別子</label>
            <input type="text" wire:model="identifier"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('identifier') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="is_public" class="rounded border-gray-300">
            公開プロジェクト(匿名/非メンバーに閲覧を許可しうる)
        </label>

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">有効なモジュール</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Enums\ProjectModuleKey::cases() as $module)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="modules" value="{{ $module->value }}" class="rounded border-gray-300">
                        {{ $module->value }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $project ? route('projects.show', $project) : route('projects.index') }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
