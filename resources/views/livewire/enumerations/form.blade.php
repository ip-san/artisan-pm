<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public EnumerationType $type;

    public ?Enumeration $enumeration = null;

    public string $name = '';

    public bool $is_default = false;

    public bool $active = true;

    public function mount(EnumerationType $type, ?Enumeration $enumeration = null): void
    {
        $this->type = $type;

        if ($enumeration?->exists) {
            // {enumeration} is a plain implicit binding by id, independent
            // of the {type} route segment — without this check, an admin
            // could reach e.g. /enumerations/document_category/{id}/edit
            // for a row that's actually an issue priority.
            abort_unless($enumeration->type === $type, 404);

            $this->authorize('update', $enumeration);

            $this->enumeration = $enumeration;
            $this->name = $enumeration->name;
            $this->is_default = $enumeration->is_default;
            $this->active = $enumeration->active;
        } else {
            $this->authorize('create', Enumeration::class);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $data['type'] = $this->type->value;

        if ($this->enumeration) {
            $this->enumeration->update($data);
        } else {
            $this->enumeration = Enumeration::create($data);
        }

        if ($data['is_default']) {
            $this->enumeration->makeDefault();
        }

        $this->redirect(route('enumerations.index', $this->type->value), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $enumeration ? '編集' : '新規作成' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="is_default" class="rounded border-gray-300">
            既定値にする
        </label>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="active" class="rounded border-gray-300">
            有効にする
        </label>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('enumerations.index', $type->value) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
