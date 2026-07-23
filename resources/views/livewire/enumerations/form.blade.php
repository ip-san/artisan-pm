<?php

use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public EnumerationType $type;

    public ?Enumeration $enumeration = null;

    public string $name = '';

    public bool $is_default = false;

    public bool $active = true;

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

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

            $this->customFieldValues = $enumeration->customFieldFormValues($enumeration->relevantCustomFields());
        } else {
            $this->authorize('create', Enumeration::class);
        }
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        return ($this->enumeration ?? new Enumeration(['type' => $this->type]))->relevantCustomFields();
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
        ];

        $rules = [...$rules, ...CustomField::formValidationRules($this->customFields)];

        $data = $this->validate($rules);
        $customFieldData = CustomField::filterEditableValues($this->customFields, $data['customFieldValues'] ?? [], auth()->user());
        unset($data['customFieldValues']);

        $data['type'] = $this->type->value;

        if ($this->enumeration) {
            $this->enumeration->update($data);
        } else {
            $this->enumeration = Enumeration::create($data);
        }

        if ($data['is_default']) {
            $this->enumeration->makeDefault();
        }

        $this->enumeration->setCustomFieldValues($customFieldData);

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

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-gray-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues" :required="$field->is_required" :disabled="! $field->editableBy(auth()->user())" />
                @endforeach
            </div>
        @endif

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
