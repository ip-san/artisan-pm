<?php

use App\Models\IssueCategory;
use App\Models\Project;
use App\Support\Issues\AssigneeChoice;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?IssueCategory $category = null;

    public string $name = '';

    public ?int $assigned_to_id = null;

    /** A group default assignee (issue_group_assignment), set instead of assigned_to_id. */
    public ?int $assigned_to_group_id = null;

    /** The assignee select's value: a user id or `group:<id>` (AssigneeChoice). */
    public string $assigneeChoice = '';

    public function mount(Project $project, ?IssueCategory $issueCategory = null): void
    {
        $this->project = $project;

        if ($issueCategory?->exists) {
            // {issueCategory} is a plain implicit binding by id, independent
            // of the {project} route segment.
            abort_unless($issueCategory->project_id === $project->id, 404);

            $this->authorize('update', $issueCategory);

            $this->category = $issueCategory;
            $this->name = $issueCategory->name;
            $this->assigned_to_id = $issueCategory->assigned_to_id;
            $this->assigned_to_group_id = $issueCategory->assigned_to_group_id;
        } else {
            $this->authorize('create', [IssueCategory::class, $project]);
        }
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->project->assignableUsers();
    }

    /**
     * Redmine offers the project's assignable principals: groups too while
     * group assignment is on, plus the category's current group.
     *
     * @return Collection<int, \App\Models\Group>
     */
    #[Computed]
    public function groups(): Collection
    {
        return AssigneeChoice::groupOptions($this->project, $this->category?->assigned_to_group_id);
    }

    public function updatedAssigneeChoice(string $value): void
    {
        ['assigned_to_id' => $this->assigned_to_id, 'assigned_to_group_id' => $this->assigned_to_group_id] = AssigneeChoice::decode($value);
    }

    public function dehydrate(): void
    {
        $this->assigneeChoice = AssigneeChoice::encode($this->assigned_to_id, $this->assigned_to_group_id);
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('issue_categories', 'name')->where('project_id', $this->project->id)->ignore($this->category?->id),
            ],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
            'assigned_to_group_id' => ['nullable', Rule::in($this->groups->pluck('id')->all())],
        ]);

        if ($data['assigned_to_group_id'] !== null) {
            $data['assigned_to_id'] = null;
        }

        $data['project_id'] = $this->project->id;

        if ($this->category) {
            $this->category->update($data);
        } else {
            IssueCategory::create($data);
        }

        $this->redirect(route('issue-categories.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">
        {{ $category ? __('カテゴリを編集') : __('新規カテゴリ') }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('名前') }}</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('既定の担当者(任意)') }}</label>
            <select wire:model="assigneeChoice" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="">{{ __('なし') }}</option>
                <x-assignee-options :users="$this->members" :groups="$this->groups" />
            </select>
            @error('assigned_to_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            @error('assigned_to_group_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('保存') }}
            </button>
            <a href="{{ route('issue-categories.index', $project) }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ __('キャンセル') }}
            </a>
        </div>
    </form>
</div>
