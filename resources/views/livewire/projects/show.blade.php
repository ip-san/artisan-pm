<?php

use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }
}; ?>

<div>
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }}</h1>
            <p class="text-sm text-gray-500">{{ $project->identifier }}</p>
        </div>
        <div class="flex gap-2">
            @can('viewAny', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    課題
                </a>
            @endcan
            @can('viewAny', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    工数
                </a>
            @endcan
            @can('viewAny', [\App\Models\WikiPage::class, $project])
                <a href="{{ route('wiki.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Wiki
                </a>
            @endcan
            @can('viewAny', [\App\Models\Board::class, $project])
                <a href="{{ route('boards.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    フォーラム
                </a>
            @endcan
            @can('viewAny', [\App\Models\News::class, $project])
                <a href="{{ route('news.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    お知らせ
                </a>
            @endcan
            @can('viewAny', [\App\Models\Document::class, $project])
                <a href="{{ route('documents.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    文書
                </a>
            @endcan
            @can('viewAny', [\App\Models\Version::class, $project])
                <a href="{{ route('files.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ファイル
                </a>
            @endcan
            @can('manageMembers', $project)
                <a href="{{ route('projects.members', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    メンバー管理
                </a>
            @endcan
            @can('update', $project)
                <a href="{{ route('projects.edit', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    編集
                </a>
            @endcan
        </div>
    </div>

    @if ($project->description)
        <p class="text-sm text-gray-700 mb-6">{{ $project->description }}</p>
    @endif

    <div class="rounded-md border border-gray-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-gray-900 mb-2">有効なモジュール</h2>
        <div class="flex flex-wrap gap-2">
            @forelse ($project->moduleAssignments as $assignment)
                <span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700">{{ $assignment->module->value }}</span>
            @empty
                <span class="text-sm text-gray-500">有効なモジュールはありません。</span>
            @endforelse
        </div>
    </div>

    @if ($project->children->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-2">サブプロジェクト</h2>
            <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
                @foreach ($project->children as $child)
                    <li class="px-4 py-2">
                        <a href="{{ route('projects.show', $child) }}" class="text-indigo-600 hover:underline">{{ $child->name }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
