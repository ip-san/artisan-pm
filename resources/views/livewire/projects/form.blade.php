<?php

use App\Enums\ProjectModuleKey;
use App\Models\CustomField;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Project;
use App\Models\Query;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Issues\AssigneeChoice;
use App\Enums\VersionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public ?Project $project = null;

    public string $name = '';

    public string $identifier = '';

    public string $description = '';

    public string $homepage = '';

    public bool $is_public = true;

    public ?int $parent_id = null;

    public bool $inherit_members = false;

    public ?int $default_version_id = null;

    public ?int $default_assigned_to_id = null;

    /** A group default assignee (issue_group_assignment), set instead of default_assigned_to_id. */
    public ?int $default_assigned_to_group_id = null;

    /** The default assignee select's value: a user id or `group:<id>` (AssigneeChoice). */
    public string $defaultAssigneeChoice = '';

    public ?int $default_issue_query_id = null;

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
            $this->homepage = (string) $project->homepage;
            $this->is_public = $project->is_public;
            $this->parent_id = $project->parent_id;
            $this->inherit_members = $project->inherit_members;
            $this->default_version_id = $project->default_version_id;
            $this->default_assigned_to_id = $project->default_assigned_to_id;
            $this->default_assigned_to_group_id = $project->default_assigned_to_group_id;
            $this->default_issue_query_id = $project->default_issue_query_id;
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

    /**
     * Redmine's project form warns a non-administrator who is a member of
     * the parent before they untick "inherit members" on a project that
     * inherits: their own access here may come only from the parent.
     */
    #[Computed]
    public function warnsBeforeLeavingInheritance(): bool
    {
        $user = auth()->user();
        $parent = $this->project?->loadMissing('parent')->parent;

        return $user !== null && ! $user->is_admin
            && $this->project?->inherit_members === true
            && $parent !== null
            && app(\App\Support\Authorization\AuthorizationService::class)->isMemberOf($user, $parent);
    }

    /**
     * Whether the publicity checkbox is offered (select_project_publicity).
     */
    #[Computed]
    public function canSelectPublicity(): bool
    {
        return Project::mayChoosePublicity(auth()->user(), $this->project);
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
     * Open shared versions, plus the current default even if it has since
     * been closed — matches Redmine's project_default_version_options, so
     * saving other settings never silently drops a stale default.
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function defaultVersionOptions(): Collection
    {
        if ($this->project === null) {
            return collect();
        }

        return $this->project->sharedVersions()
            ->filter(fn (Version $version) => $version->status === VersionStatus::Open
                || $version->id === $this->project->default_version_id)
            ->sortBy('name')
            ->values();
    }

    /**
     * Public issue queries the project's list can open on: site-wide ones and
     * the project's own (Redmine requires public so every member can use it).
     *
     * @return Collection<int, Query>
     */
    #[Computed]
    public function defaultQueryOptions(): Collection
    {
        return Query::query()
            ->where('type', QueryType::Issue->value)
            ->where('visibility', QueryVisibility::Public->value)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $this->project?->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Assignable members, plus the current default assignee even if they
     * have since lost that role (Redmine's project_default_assigned_to_options).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function defaultAssigneeOptions(): Collection
    {
        if ($this->project === null) {
            return collect();
        }

        return $this->project->assignableUsers()
            ->when(
                $this->project->defaultAssignedTo !== null,
                fn (Collection $users) => $users->push($this->project->defaultAssignedTo)
            )
            ->unique('id')
            ->pipe(fn (Collection $users) => User::sortByFormat($users));
    }

    /**
     * Groups offered as the default assignee: the assignable groups while
     * group assignment is on, plus the current default group.
     *
     * @return Collection<int, \App\Models\Group>
     */
    #[Computed]
    public function defaultAssigneeGroupOptions(): Collection
    {
        if ($this->project === null) {
            return collect();
        }

        return AssigneeChoice::groupOptions($this->project, $this->project->default_assigned_to_group_id);
    }

    public function updatedDefaultAssigneeChoice(string $value): void
    {
        ['assigned_to_id' => $this->default_assigned_to_id, 'assigned_to_group_id' => $this->default_assigned_to_group_id] = AssigneeChoice::decode($value);
    }

    public function updatedDefaultAssignedToId(?int $value): void
    {
        if ($value !== null) {
            $this->default_assigned_to_group_id = null;
        }
    }

    public function updatedDefaultAssignedToGroupId(?int $value): void
    {
        if ($value !== null) {
            $this->default_assigned_to_id = null;
        }
    }

    public function dehydrate(): void
    {
        $this->defaultAssigneeChoice = AssigneeChoice::encode($this->default_assigned_to_id, $this->default_assigned_to_group_id);
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
            } else {
                // Both "creating a brand-new top-level project" and
                // "detaching an existing subproject to become top-level"
                // need the same permission — matches Redmine's
                // Project#allowed_parents, which only offers nil as a
                // valid target when the user holds the global add_project
                // permission (this app: ProjectPolicy::create(), admin-only).
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
            'homepage' => ['nullable', 'string', 'max:255'],
            'is_public' => ['boolean'],
            'inherit_members' => ['boolean'],
            // Allowed parents are the permission-filtered list, plus this
            // project's own current parent (if any) — so leaving parent_id
            // untouched on an ordinary edit never fails validation just
            // because the editor doesn't hold createSubproject on a parent
            // that was set before they had that permission or ever needed it.
            'parent_id' => ['nullable', Rule::in([...$this->availableParents->pluck('id')->all(), $this->project?->parent_id])],
            'trackerIds' => ['required', 'array', 'min:1'],
            'trackerIds.*' => ['exists:trackers,id'],
        ];

        if ($this->project !== null) {
            // Only offered when editing: a brand-new project has no
            // versions or members to choose from yet (Redmine likewise
            // exposes both on the settings page only).
            $rules['default_version_id'] = ['nullable', Rule::in($this->defaultVersionOptions->pluck('id')->all())];
            $rules['default_assigned_to_id'] = ['nullable', Rule::in($this->defaultAssigneeOptions->pluck('id')->all())];
            $rules['default_assigned_to_group_id'] = ['nullable', Rule::in($this->defaultAssigneeGroupOptions->pluck('id')->all())];
            $rules['default_issue_query_id'] = ['nullable', Rule::in($this->defaultQueryOptions->pluck('id')->all())];
        }

        $rules = [...$rules, ...CustomField::formValidationRules($this->customFields)];

        $data = $this->validate($rules);
        $customFieldData = CustomField::filterEditableValues($this->customFields, $data['customFieldValues'] ?? [], auth()->user());
        $trackerIds = $data['trackerIds'];
        unset($data['customFieldValues'], $data['trackerIds']);

        // Without select_project_publicity the posted value is ignored: an
        // existing project keeps what it has, a new one takes the site default.
        if (! $this->canSelectPublicity) {
            if ($this->project) {
                unset($data['is_public']);
            } else {
                $data['is_public'] = Setting::get('default_projects_public', true);
            }
        }

        // Redmine's safe_attributes: inherit_members may only be set by
        // someone who can see the parent whose members it would copy.
        if (! Project::mayChooseInheritMembers(auth()->user(), $data['parent_id'])) {
            if ($this->project) {
                unset($data['inherit_members']);
            } else {
                $data['inherit_members'] = false;
            }
        }

        if ($this->project) {
            $removedTrackerIds = $this->project->trackers->pluck('id')->diff($trackerIds);

            if ($removedTrackerIds->isNotEmpty()) {
                $blockedTrackerNames = Tracker::query()
                    ->whereIn('id', $removedTrackerIds)
                    ->whereHas('issues', fn ($query) => $query->where('project_id', $this->project->id))
                    ->pluck('name');

                if ($blockedTrackerNames->isNotEmpty()) {
                    $this->addError('trackerIds', __('このプロジェクトの課題で使用中のため外せません: :trackers', ['trackers' => $blockedTrackerNames->join(', ')]));

                    return;
                }
            }

            $this->project->update($data);
        } else {
            $this->project = Project::create($data);

            // Matches Redmine's ProjectsController#create, which only
            // auto-adds the creator as a member for non-admins — an admin
            // already sees every project regardless of membership, so
            // granting them a role here would be a meaningless no-op at
            // best and a confusing extra role at worst.
            if (! auth()->user()->is_admin) {
                $this->project->addDefaultMember(auth()->user());
            }
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
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">
        {{ $project ? __('プロジェクトを編集') : __('新規プロジェクト') }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('名前') }}</label>
            <input type="text" wire:model.live.debounce.400ms="name"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('識別子') }}</label>
            <input type="text" wire:model="identifier"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('identifier') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('説明') }}</label>
            <textarea wire:model="description" rows="3"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('ホームページ') }}</label>
            <input type="text" wire:model="homepage"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('homepage') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        @if ($this->availableParents->isNotEmpty())
            <div>
                <label class="block text-sm font-medium text-neutral-700">{{ __('親プロジェクト') }}</label>
                <select wire:model="parent_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('なし(最上位プロジェクト)') }}</option>
                    @foreach ($this->availableParents as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                @error('parent_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input type="checkbox" wire:model="inherit_members" class="rounded border-neutral-300"
                @if ($this->warnsBeforeLeavingInheritance)
                    x-data x-on:click="if (! $el.checked && ! confirm(@js(__('一部またはすべての権限を自分自身から剥奪しようとしているため、このプロジェクトを編集できなくなる可能性があります。本当に続けますか?')))) { $event.preventDefault() }"
                    data-confirm-leaving-inheritance
                @endif>
            {{ __('メンバーを継承') }}
        </label>

        @if ($this->canSelectPublicity)
            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="is_public" class="rounded border-neutral-300">
                {{ __('公開プロジェクト(匿名/非メンバーに閲覧を許可しうる)') }}
            </label>
        @endif

        <div>
            <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('有効なモジュール') }}</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Enums\ProjectModuleKey::cases() as $module)
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" wire:model="modules" value="{{ $module->value }}" class="rounded border-neutral-300">
                        {{ $module->value }}
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('使用するトラッカー') }}</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($this->trackers as $tracker)
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" wire:model="trackerIds" value="{{ $tracker->id }}" class="rounded border-neutral-300">
                        {{ $tracker->name }}
                    </label>
                @endforeach
            </div>
            @error('trackerIds') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            @if ($this->trackers->isEmpty())
                <p class="mt-1 text-xs text-warning">
                    {!! __('トラッカーが登録されていません。先に :link してください。', ['link' => '<a href="'.e(route('trackers.create')).'" class="underline">'.e(__('トラッカーを作成')).'</a>']) !!}
                </p>
            @endif
        </div>

        @if ($project)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-neutral-700">{{ __('既定の対象バージョン') }}</label>
                    <select wire:model="default_version_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="">{{ __('なし') }}</option>
                        @foreach ($this->defaultVersionOptions as $version)
                            <option value="{{ $version->id }}">{{ $version->name }}</option>
                        @endforeach
                    </select>
                    @error('default_version_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-neutral-700">{{ __('課題一覧の既定クエリ') }}</label>
                    <select wire:model="default_issue_query_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="">{{ __('指定しない') }}</option>
                        @foreach ($this->defaultQueryOptions as $query)
                            <option value="{{ $query->id }}">{{ $query->name }}</option>
                        @endforeach
                    </select>
                    @error('default_issue_query_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-neutral-700">{{ __('既定の担当者') }}</label>
                    <select wire:model="defaultAssigneeChoice" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="">{{ __('なし') }}</option>
                        <x-assignee-options :users="$this->defaultAssigneeOptions" :groups="$this->defaultAssigneeGroupOptions" />
                    </select>
                    @error('default_assigned_to_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                    @error('default_assigned_to_group_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-neutral-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues" :record="$project" :current="$customFieldValues[$field->id] ?? null" :required="$field->is_required" :disabled="! $field->editableBy(auth()->user())" />
                @endforeach
            </div>
        @endif

        <div class="flex gap-3">
            <button type="submit"
                class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('保存') }}
            </button>
            <a href="{{ $project ? route('projects.show', $project) : route('projects.index') }}"
                class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ __('キャンセル') }}
            </a>
        </div>
    </form>
</div>
