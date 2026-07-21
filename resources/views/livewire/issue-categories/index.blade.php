<?php

use App\Models\IssueCategory;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [IssueCategory::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function categories(): Collection
    {
        return $this->project->issueCategories()->with('assignedTo')->orderBy('name')->get();
    }

    public function delete(int $categoryId): void
    {
        $category = IssueCategory::query()->where('project_id', $this->project->id)->findOrFail($categoryId);
        $this->authorize('delete', $category);

        if ($category->issues()->exists()) {
            session()->flash('error', 'このカテゴリを使用している課題があるため削除できません。');

            return;
        }

        $category->delete();

        unset($this->categories);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — 課題カテゴリ</h1>
        <a href="{{ route('issue-categories.create', $project) }}"
            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            新規カテゴリ
        </a>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->categories as $category)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $category->name }}</span>
                    @if ($category->assignedTo)
                        <span class="ml-2 text-xs text-gray-500">既定の担当者: {{ $category->assignedTo->name }}</span>
                    @endif
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('issue-categories.edit', [$project, $category]) }}" class="text-sm text-indigo-600 hover:underline">編集</a>
                    <button wire:click="delete({{ $category->id }})" wire:confirm="このカテゴリを削除しますか?"
                        class="text-sm text-red-600 hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">カテゴリがありません。</li>
        @endforelse
    </ul>
</div>
