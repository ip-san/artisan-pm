<?php

use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    #[Url]
    public bool $bookmarkedOnly = false;

    #[Computed]
    public function projects(): Collection
    {
        $projects = Project::query()
            ->whereDoesntHave('parent')
            ->with('children')
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project));

        if ($this->bookmarkedOnly && auth()->user()) {
            $bookmarkedIds = auth()->user()->bookmarkedProjects()->pluck('projects.id');

            return $projects->filter(fn (Project $project) => $bookmarkedIds->contains($project->id))->values();
        }

        return $projects;
    }

    public function toggleBookmark(int $projectId): void
    {
        $user = auth()->user();

        if ($user->bookmarkedProjects()->where('projects.id', $projectId)->exists()) {
            $user->bookmarkedProjects()->detach($projectId);
        } else {
            $user->bookmarkedProjects()->attach($projectId);
        }

        unset($this->projects);
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">プロジェクト</h1>
        @can('create', \App\Models\Project::class)
            <a href="{{ route('projects.create') }}"
                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                新規プロジェクト
            </a>
        @endcan
    </div>

    <label class="mb-3 flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" wire:model.live="bookmarkedOnly" class="rounded border-gray-300">
        ブックマークしたプロジェクトのみ表示
    </label>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->projects as $project)
            <li class="flex items-start justify-between px-4 py-3">
                <div>
                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-indigo-600 hover:underline">
                        {{ $project->name }}
                    </a>
                    <span class="ml-2 text-xs text-gray-500">{{ $project->identifier }}</span>
                    @unless ($project->is_public)
                        <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">非公開</span>
                    @endunless
                    @if ($project->description)
                        <p class="mt-1 text-sm text-gray-600">{{ $project->description }}</p>
                    @endif
                </div>
                <button wire:click="toggleBookmark({{ $project->id }})" wire:key="bookmark-{{ $project->id }}"
                    class="shrink-0 text-lg leading-none {{ $project->isBookmarkedBy(auth()->user()) ? 'text-amber-500' : 'text-gray-300 hover:text-gray-400' }}"
                    title="ブックマーク">
                    ★
                </button>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">プロジェクトがありません。</li>
        @endforelse
    </ul>
</div>
