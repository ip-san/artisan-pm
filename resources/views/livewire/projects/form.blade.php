<?php

use App\Enums\ProjectModuleKey;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Setting;
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

    public ?int $parent_id = null;

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
            $this->parent_id = $project->parent_id;
            $this->modules = $project->moduleAssignments->pluck('module.value')->all();
            $this->trackerIds = $project->trackers->pluck('id')->all();

            $this->customFieldValues = $project->customFieldFormValues($project->relevantCustomFields());
        } else {
            // A parent_id in the query string (arrived at via a project's
            // "add subproject" link) is authorized against that specific
            // parent's add_subprojects permission — less restrictive than
            // top-level project creation, which stays admin-only.
            $requestedParentId = request()->integer('parent_id') ?: null;
            $parentProject = $requestedParentId !== null ? Project::query()->find($requestedParentId) : null;

            if ($parentProject !== null) {
                $this->authorize('createSubproject', $parentProject);
                $this->parent_id = $parentProject->id;
            } else {
                $this->authorize('create', Project::class);
            }

            $this->is_public = Setting::get('default_projects_public', true);

            $this->modules = Setting::get(
                'default_projects_modules',
                array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults())
            );

            // Empty setting (never configured, or explicitly cleared) falls
            // back to every tracker, matching Redmine's own
            // default_projects_tracker_ids behavior.
            $defaultTrackerIds = Setting::get('default_projects_tracker_ids', []);
            $this->trackerIds = $defaultTrackerIds !== []
                ? $defaultTrackerIds
                : Tracker::query()->pluck('id')->all();
        }
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    /**
     * Every project that could legally become this one's parent: not
     * itself or its own descendants (which would create a cycle in the
     * nested set), and — since this drives both the dropdown and the
     * Rule::in() allowlist in save() — only ones the current user
     * actually holds createSubproject on, so the list can't be used to
     * either submit an unauthorized parent or discover private project
     * names via the dropdown.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function availableParents(): Collection
    {
        $excludedIds = $this->project
            ? $this->project->descendants()->pluck('id')->push($this->project->id)
            : collect();

        return Project::query()
            ->whereNotIn('id', $excludedIds)
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $candidate) => auth()->user()?->can('createSubproject', $candidate))
            ->values();
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
        // Re-authorize against whatever parent_id is actually about to be
        // submitted — mount() only checked the query-string parent at load
        // time, and parent_id is a public property a client could still
        // change before calling save(). Only re-check when it's actually
        // changing (or this is a new project), so editing an existing
        // subproject's other fields doesn't newly require createSubproject
        // on a parent that was never being touched.
        $parentChanged = $this->project === null || $this->parent_id !== $this->project->parent_id;

        if ($parentChanged) {
            if ($this->parent_id !== null) {
                $this->authorize('createSubproject', Project::findOrFail($this->parent_id));
            } elseif ($this->project === null) {
                $this->authorize('create', Project::class);
            }
        }

        // Matches Redmine's Project#set_default_values, applied at save
        // time rather than prefilled on the form (Redmine itself leaves
        // the field blank on the "new project" page — the browser's own
        // name-to-identifier auto-slugify, mirrored by updatedName()
        // above, is what usually fills it before submission; this only
        // ever kicks in when the identifier is still genuinely blank).
        if ($this->project === null && $this->identifier === '' && Setting::get('sequential_project_identifiers', false)) {
            $this->identifier = Project::nextIdentifier() ?? '';
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'identifier' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('projects', 'identifier')->ignore($this->project?->id),
            ],
            'description' => ['nullable', 'string'],
            'is_public' => ['boolean'],
            // Allowed parents are the permission-filtered list, plus this
            // project's own current parent (if any) — so leaving parent_id
            // untouched on an ordinary edit never fails validation just
            // because the editor doesn't hold createSubproject on a parent
            // that was set before they had that permission or ever needed it.
            'parent_id' => ['nullable', Rule::in([...$this->availableParents->pluck('id')->all(), $this->project?->parent_id])],
            'trackerIds' => ['required', 'array', 'min:1'],
            'trackerIds.*' => ['exists:trackers,id'],
        ];

        $rules = [...$rules, ...CustomField::formValidationRules($this->customFields)];

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

        @if ($this->availableParents->isNotEmpty())
            <div>
                <label class="block text-sm font-medium text-gray-700">親プロジェクト</label>
                <select wire:model="parent_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">なし(最上位プロジェクト)</option>
                    @foreach ($this->availableParents as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                @error('parent_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

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
