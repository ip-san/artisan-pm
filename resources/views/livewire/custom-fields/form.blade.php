<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?CustomField $customField = null;

    public string $name = '';

    public string $field_format = '';

    public bool $is_required = false;

    public bool $multiple = false;

    public ?int $min_length = null;

    public ?int $max_length = null;

    public string $possibleValuesText = '';

    /** @var array<int> */
    public array $trackerIds = [];

    /** @var array<int> */
    public array $projectIds = [];

    /** @var array<int> */
    public array $roleIds = [];

    public function mount(?CustomField $customField = null): void
    {
        if ($customField?->exists) {
            $this->authorize('update', $customField);

            $this->customField = $customField;
            $this->name = $customField->name;
            $this->field_format = $customField->field_format->value;
            $this->is_required = $customField->is_required;
            $this->multiple = $customField->multiple;
            $this->min_length = $customField->min_length;
            $this->max_length = $customField->max_length;
            $this->possibleValuesText = implode("\n", $customField->possible_values ?? []);
            $this->trackerIds = $customField->trackers->pluck('id')->all();
            $this->projectIds = $customField->projects->pluck('id')->all();
            $this->roleIds = $customField->roles->pluck('id')->all();
        } else {
            $this->authorize('create', CustomField::class);
        }
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get();
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->whereNull('builtin')->orderBy('position')->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'field_format' => ['required', Rule::enum(CustomFieldFormat::class)],
            'is_required' => ['boolean'],
            'multiple' => ['boolean'],
            'min_length' => ['nullable', 'integer', 'min:0'],
            'max_length' => ['nullable', 'integer', 'min:0'],
            'trackerIds' => ['required', 'array', 'min:1'],
            'trackerIds.*' => ['exists:trackers,id'],
            'projectIds' => ['array'],
            'projectIds.*' => ['exists:projects,id'],
            'roleIds' => ['array'],
            'roleIds.*' => ['exists:roles,id'],
        ]);

        $possibleValues = $this->field_format === CustomFieldFormat::List->value
            ? array_values(array_filter(array_map('trim', explode("\n", $this->possibleValuesText))))
            : null;

        $attributes = [
            'name' => $data['name'],
            'field_format' => $data['field_format'],
            'customized_type' => CustomizableType::Issue->value,
            'is_required' => $data['is_required'],
            'multiple' => $data['multiple'],
            'min_length' => $data['min_length'],
            'max_length' => $data['max_length'],
            'possible_values' => $possibleValues,
        ];

        if ($this->customField) {
            $this->customField->update($attributes);
        } else {
            $this->customField = CustomField::create($attributes);
        }

        $this->customField->trackers()->sync($data['trackerIds']);
        $this->customField->projects()->sync($data['projectIds']);
        $this->customField->roles()->sync($data['roleIds']);

        $this->redirect(route('custom-fields.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $customField ? 'カスタムフィールドを編集' : '新規カスタムフィールド' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">形式</label>
            <select wire:model.live="field_format" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">選択してください</option>
                @foreach (\App\Enums\CustomFieldFormat::cases() as $format)
                    <option value="{{ $format->value }}">{{ $format->value }}</option>
                @endforeach
            </select>
            @error('field_format') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if ($field_format === \App\Enums\CustomFieldFormat::List->value)
            <div>
                <label class="block text-sm font-medium text-gray-700">選択肢(1行に1つ)</label>
                <textarea wire:model="possibleValuesText" rows="4"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            </div>
        @endif

        @if ($field_format === \App\Enums\CustomFieldFormat::String->value)
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">最小文字数</label>
                    <input type="number" wire:model="min_length" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">最大文字数</label>
                    <input type="number" wire:model="max_length" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                </div>
            </div>
        @endif

        <div class="flex gap-6">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="is_required" class="rounded border-gray-300">
                必須項目にする
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="multiple" class="rounded border-gray-300">
                複数値を許可する
            </label>
        </div>

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">対象トラッカー</span>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->trackers as $tracker)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="trackerIds" value="{{ $tracker->id }}" class="rounded border-gray-300">
                        {{ $tracker->name }}
                    </label>
                @endforeach
            </div>
            @error('trackerIds') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">対象プロジェクト(未選択=全プロジェクト)</span>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->projects as $project)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="projectIds" value="{{ $project->id }}" class="rounded border-gray-300">
                        {{ $project->name }}
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">閲覧可能ロール(未選択=全ロールに表示)</span>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->roles as $role)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}" class="rounded border-gray-300">
                        {{ $role->name }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('custom-fields.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
