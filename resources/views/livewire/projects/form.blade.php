<?php

use App\Enums\ProjectModuleKey;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
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

    /** @var array<int> */
    public array $trackerIds = [];

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

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
            $this->trackerIds = $project->trackers->pluck('id')->all();

            foreach ($project->relevantCustomFields() as $field) {
                $this->customFieldValues[$field->id] = $field->multiple
                    ? $project->customFieldValues->where('custom_field_id', $field->id)->map(fn ($v) => $v->value())->all()
                    : $project->customValue($field);
            }
        } else {
            $this->authorize('create', Project::class);

            $this->modules = array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults());
        }
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        return ($this->project ?? new Project)->relevantCustomFields();
    }

    public function updatedName(string $value): void
    {
        if (! $this->project) {
            $this->identifier = Str::slug($value);
        }
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'identifier' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('projects', 'identifier')->ignore($this->project?->id),
            ],
            'description' => ['nullable', 'string'],
            'is_public' => ['boolean'],
            'trackerIds' => ['required', 'array', 'min:1'],
            'trackerIds.*' => ['exists:trackers,id'],
        ];

        foreach ($this->customFields as $field) {
            $key = "customFieldValues.{$field->id}";
            $presence = $field->is_required ? 'required' : 'nullable';

            if ($field->multiple) {
                $rules[$key] = [$presence, 'array'];
                $rules["{$key}.*"] = $field->format()->validationRules($field);
            } else {
                $rules[$key] = [$presence, ...$field->format()->validationRules($field)];
            }
        }

        $data = $this->validate($rules);
        $customFieldData = $data['customFieldValues'] ?? [];
        $trackerIds = $data['trackerIds'];
        unset($data['customFieldValues'], $data['trackerIds']);

        if ($this->project) {
            $removedTrackerIds = $this->project->trackers->pluck('id')->diff($trackerIds);

            if ($removedTrackerIds->isNotEmpty()) {
                $blockedTrackerNames = Tracker::query()
                    ->whereIn('id', $removedTrackerIds)
                    ->whereHas('issues', fn ($query) => $query->where('project_id', $this->project->id))
                    ->pluck('name');

                if ($blockedTrackerNames->isNotEmpty()) {
                    $this->addError('trackerIds', 'このプロジェクトの課題で使用中のため外せません: '.$blockedTrackerNames->join(', '));

                    return;
                }
            }

            $this->project->update($data);
        } else {
            $this->project = Project::create($data);
        }

        $this->project->syncModules(
            collect($this->modules)->map(fn (string $m) => ProjectModuleKey::from($m))->all()
        );

        $this->project->trackers()->sync($trackerIds);

        $this->project->setCustomFieldValues($customFieldData);

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

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">使用するトラッカー</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($this->trackers as $tracker)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="trackerIds" value="{{ $tracker->id }}" class="rounded border-gray-300">
                        {{ $tracker->name }}
                    </label>
                @endforeach
            </div>
            @error('trackerIds') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @if ($this->trackers->isEmpty())
                <p class="mt-1 text-xs text-amber-600">
                    トラッカーが登録されていません。先に <a href="{{ route('trackers.create') }}" class="underline">トラッカーを作成</a> してください。
                </p>
            @endif
        </div>

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-gray-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues" :required="$field->is_required" />
                @endforeach
            </div>
        @endif

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
