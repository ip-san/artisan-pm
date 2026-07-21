<?php

use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()
            ->whereDoesntHave('parent')
            ->with('children')
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => auth()->user()?->can('view', $project));
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

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->projects as $project)
            <li class="px-4 py-3">
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
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">プロジェクトがありません。</li>
        @endforelse
    </ul>
</div>
