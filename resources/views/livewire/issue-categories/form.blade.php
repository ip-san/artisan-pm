<?php

use App\Models\IssueCategory;
use App\Models\Project;
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
        } else {
            $this->authorize('create', [IssueCategory::class, $project]);
        }
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->project->users;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('issue_categories', 'name')->where('project_id', $this->project->id)->ignore($this->category?->id),
            ],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
        ]);

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
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $category ? 'カテゴリを編集' : '新規カテゴリ' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">既定の担当者(任意)</label>
            <select wire:model="assigned_to_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし</option>
                @foreach ($this->members as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
            @error('assigned_to_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('issue-categories.index', $project) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
