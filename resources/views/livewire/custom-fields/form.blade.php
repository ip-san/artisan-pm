<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
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

    public string $customized_type = '';

    public bool $is_required = false;

    public bool $multiple = false;

    public ?int $min_length = null;

    public ?int $max_length = null;

    public string $regexp = '';

    public string $default_value = '';

    public bool $searchable = false;

    public bool $editable = true;

    public string $possibleValuesText = '';

    /** @var array<int, array{id: ?int, name: string, active: bool, reassignTo: string}> */
    public array $enumerationOptions = [];

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
            $this->customized_type = $customField->customized_type->value;
            $this->is_required = $customField->is_required;
            $this->multiple = $customField->multiple;
            $this->min_length = $customField->min_length;
            $this->max_length = $customField->max_length;
            $this->regexp = (string) $customField->regexp;
            $this->default_value = (string) $customField->default_value;
            $this->searchable = $customField->searchable;
            $this->editable = $customField->editable;
            $this->possibleValuesText = implode("\n", $customField->possible_values ?? []);
            $this->enumerationOptions = $customField->enumerationOptions
                ->map(fn (CustomFieldEnumeration $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'active' => $option->active,
                    'reassignTo' => '',
                ])
                ->all();
            $this->trackerIds = $customField->trackers->pluck('id')->all();
            $this->projectIds = $customField->projects->pluck('id')->all();
            $this->roleIds = $customField->roles->pluck('id')->all();
        } else {
            $this->authorize('create', CustomField::class);

            $this->customized_type = CustomizableType::Issue->value;
        }
    }

    public function isForIssues(): bool
    {
        return $this->customized_type === CustomizableType::Issue->value;
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
        return Role::query()->givable()->get();
    }

    public function addEnumerationOption(): void
    {
        $this->enumerationOptions[] = ['id' => null, 'name' => '', 'active' => true, 'reassignTo' => ''];
    }

    public function removeEnumerationOption(int $index): void
    {
        unset($this->enumerationOptions[$index]);
        $this->enumerationOptions = array_values($this->enumerationOptions);
    }

    /**
     * Deletes a persisted option immediately (not deferred to save());
     * the reassign-or-clear semantics live on
     * CustomFieldEnumeration::deleteAndReassign().
     */
    public function deleteEnumerationOption(int $index): void
    {
        $option = $this->enumerationOptions[$index] ?? null;

        if ($option === null || $option['id'] === null) {
            $this->removeEnumerationOption($index);

            return;
        }

        $enumeration = CustomFieldEnumeration::find($option['id']);

        if ($enumeration === null) {
            $this->removeEnumerationOption($index);

            return;
        }

        $enumeration->deleteAndReassign($option['reassignTo'] !== '' ? (int) $option['reassignTo'] : null);

        $this->removeEnumerationOption($index);
    }

    /**
     * Deleting an option happens immediately via deleteEnumerationOption()
     * above, so this only ever adds new rows or updates existing ones —
     * name and active flag for persisted options, name/active/position
     * for brand new ones (position is simply insertion order; reordering
     * existing options isn't supported by this form).
     */
    private function saveEnumerationOptions(): void
    {
        assert($this->customField instanceof CustomField);

        foreach ($this->enumerationOptions as $position => $option) {
            $name = trim($option['name']);

            if ($name === '') {
                continue;
            }

            if ($option['id'] !== null) {
                $this->customField->enumerationOptions()->where('id', $option['id'])->update([
                    'name' => $name,
                    'active' => (bool) $option['active'],
                ]);
            } else {
                $this->customField->enumerationOptions()->create([
                    'name' => $name,
                    'active' => (bool) $option['active'],
                    'position' => $position + 1,
                ]);
            }
        }
    }

    public function save(): void
    {
        // customized_type and field_format are both fixed at creation and
        // never re-submitted from the edit form's (disabled) selectors, so
        // they're read from the existing record rather than trusted from
        // client input — the CustomField model additionally reverts any
        // format change on update as a backstop.
        $customizedType = $this->customField?->customized_type->value ?? $this->customized_type;
        $fieldFormat = $this->customField?->field_format->value ?? $this->field_format;
        $isForIssues = $customizedType === CustomizableType::Issue->value;

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            ...($this->customField ? [] : ['field_format' => ['required', Rule::enum(CustomFieldFormat::class)]]),
            'is_required' => ['boolean'],
            'multiple' => ['boolean'],
            'min_length' => ['nullable', 'integer', 'min:0'],
            'max_length' => ['nullable', 'integer', 'min:0'],
            'regexp' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === '' || $value === null) {
                    return;
                }

                if (@preg_match('/'.str_replace('/', '\/', $value).'/', '') === false) {
                    $fail('正規表現の形式が正しくありません。');
                }
            }],
            'default_value' => ['nullable', 'string'],
            'searchable' => ['boolean'],
            'editable' => ['boolean'],
            'trackerIds' => $isForIssues ? ['required', 'array', 'min:1'] : ['array'],
            'trackerIds.*' => ['exists:trackers,id'],
            'projectIds' => ['array'],
            'projectIds.*' => ['exists:projects,id'],
            'roleIds' => ['array'],
            'roleIds.*' => ['exists:roles,id'],
            'enumerationOptions.*.name' => ['nullable', 'string', 'max:60'],
        ]);

        $possibleValues = $fieldFormat === CustomFieldFormat::List->value
            ? array_values(array_filter(array_map('trim', explode("\n", $this->possibleValuesText))))
            : null;

        $attributes = [
            'name' => $data['name'],
            'field_format' => $fieldFormat,
            'customized_type' => $customizedType,
            'is_required' => $data['is_required'],
            'multiple' => $data['multiple'],
            'min_length' => $data['min_length'],
            'max_length' => $data['max_length'],
            'regexp' => $data['regexp'] !== '' ? $data['regexp'] : null,
            'default_value' => $data['default_value'] !== '' ? $data['default_value'] : null,
            'searchable' => $data['searchable'],
            'editable' => $data['editable'],
            'possible_values' => $possibleValues,
        ];

        if ($this->customField) {
            $this->customField->update($attributes);
        } else {
            $this->customField = CustomField::create($attributes);
        }

        if ($fieldFormat === CustomFieldFormat::Enumeration->value) {
            $this->saveEnumerationOptions();
        }

        if ($isForIssues) {
            $this->customField->trackers()->sync($data['trackerIds']);
            $this->customField->projects()->sync($data['projectIds']);
        } else {
            $this->customField->trackers()->sync([]);
            $this->customField->projects()->sync([]);
        }

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
            <label class="block text-sm font-medium text-gray-700">対象</label>
            @if ($customField)
                <p class="mt-1 text-sm text-gray-900">{{ $customized_type }}(作成後は変更できません)</p>
            @else
                <select wire:model.live="customized_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach (\App\Enums\CustomizableType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->value }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">形式</label>
            @if ($customField)
                <p class="mt-1 text-sm text-gray-900">{{ $field_format }}(作成後は変更できません)</p>
            @else
                <select wire:model.live="field_format" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach (\App\Enums\CustomFieldFormat::cases() as $format)
                        <option value="{{ $format->value }}">{{ $format->value }}</option>
                    @endforeach
                </select>
                @error('field_format') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @endif
        </div>

        @if ($field_format === \App\Enums\CustomFieldFormat::List->value)
            <div>
                <label class="block text-sm font-medium text-gray-700">選択肢(1行に1つ)</label>
                <textarea wire:model="possibleValuesText" rows="4"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            </div>
        @endif

        @if ($field_format === \App\Enums\CustomFieldFormat::Enumeration->value)
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">選択肢(管理された一覧)</label>
                <p class="mb-2 text-xs text-gray-500">
                    「リスト選択」と異なり、各選択肢は個別に無効化(既存の値は保持したまま新規選択肢から外す)したり、
                    削除時に別の選択肢へ置き換えたりできます。
                </p>
                <div class="space-y-2">
                    @foreach ($enumerationOptions as $index => $option)
                        <div class="flex items-center gap-2" wire:key="cf-enum-option-{{ $option['id'] ?? 'new-'.$index }}">
                            <input type="text" wire:model="enumerationOptions.{{ $index }}.name" placeholder="選択肢名"
                                class="block w-36 rounded-md border-gray-300 text-sm shadow-sm">
                            <label class="flex items-center gap-1 text-xs text-gray-600">
                                <input type="checkbox" wire:model="enumerationOptions.{{ $index }}.active" class="rounded border-gray-300">
                                有効
                            </label>
                            @if ($option['id'])
                                <select wire:model="enumerationOptions.{{ $index }}.reassignTo"
                                    class="block w-48 rounded-md border-gray-300 text-xs shadow-sm">
                                    <option value="">削除時: 未設定にする</option>
                                    @foreach ($enumerationOptions as $other)
                                        @if (($other['id'] ?? null) !== null && $other['id'] !== $option['id'])
                                            <option value="{{ $other['id'] }}">削除時: 「{{ $other['name'] }}」に置き換え</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="button" wire:click="deleteEnumerationOption({{ $index }})"
                                    wire:confirm="この選択肢を削除しますか?"
                                    class="shrink-0 text-xs text-red-600 hover:underline">削除</button>
                            @else
                                <button type="button" wire:click="removeEnumerationOption({{ $index }})"
                                    class="shrink-0 text-xs text-gray-500 hover:underline">取消</button>
                            @endif
                        </div>
                        @error("enumerationOptions.{$index}.name") <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @endforeach
                </div>
                <button type="button" wire:click="addEnumerationOption" class="mt-2 text-xs text-indigo-600 hover:underline">
                    + 選択肢を追加
                </button>
            </div>
        @endif

        @if (in_array($field_format, [\App\Enums\CustomFieldFormat::String->value, \App\Enums\CustomFieldFormat::Link->value], true))
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

        <div>
            <label class="block text-sm font-medium text-gray-700">正規表現による検証(任意)</label>
            <input type="text" wire:model="regexp" placeholder="例: ^[A-Z]{2}\d{4}$"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('regexp') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">既定値(任意、新規課題作成時に自動入力)</label>
            <input type="text" wire:model="default_value" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('default_value') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-6">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="is_required" class="rounded border-gray-300">
                必須項目にする
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="multiple" class="rounded border-gray-300">
                複数値を許可する
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="searchable" class="rounded border-gray-300">
                検索対象にする
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="editable" class="rounded border-gray-300">
                編集可能にする(管理者は常に編集可能)
            </label>
        </div>

        @if ($this->isForIssues())
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
        @endif

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
