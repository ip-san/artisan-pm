<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
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

    /**
     * This project's own logged hours — matches Redmine's project overview
     * @total_hours, but without the display_subprojects_issues-driven
     * subproject rollup (no such setting exists in this app yet), so it's
     * this project's TimeEntry rows only.
     */
    #[Computed]
    public function totalSpentHours(): float
    {
        return (float) $this->project->timeEntries()->sum('hours');
    }

    public function toggleBookmark(): void
    {
        $user = auth()->user();

        if ($this->project->isBookmarkedBy($user)) {
            $user->bookmarkedProjects()->detach($this->project->id);
        } else {
            $user->bookmarkedProjects()->attach($this->project->id);
        }
    }

    public function closeProject(): void
    {
        $this->authorize('close', $this->project);

        $this->setStatus(ProjectStatus::Closed);
    }

    public function reopenProject(): void
    {
        $this->authorize('close', $this->project);

        $this->setStatus(ProjectStatus::Active);
    }

    public function archiveProject(): void
    {
        $this->authorize('archive', $this->project);

        $this->setStatus(ProjectStatus::Archived);
    }

    public function unarchiveProject(): void
    {
        $this->authorize('archive', $this->project);

        $this->setStatus(ProjectStatus::Active);
    }

    /**
     * status is deliberately excluded from Project's #[Fillable] — it's
     * only ever meant to change through these explicit, permission-gated
     * actions, not through the general edit form's mass assignment.
     */
    private function setStatus(ProjectStatus $status): void
    {
        $this->project->status = $status;
        $this->project->save();
    }
}; ?>

<div>
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">
                {{ $project->name }}
                @unless ($project->isOpen())
                    <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 align-middle">
                        {{ $project->status === \App\Enums\ProjectStatus::Archived ? 'アーカイブ済み' : 'クローズ' }}
                    </span>
                @endunless
            </h1>
            <p class="text-sm text-gray-500">{{ $project->identifier }}</p>
        </div>
        <div class="flex gap-2">
            <button wire:click="toggleBookmark" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ $project->isBookmarkedBy(auth()->user()) ? '★ ブックマーク解除' : '☆ ブックマーク' }}
            </button>
            <a href="{{ route('activity.index', $project) }}"
                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                活動
            </a>
            <a href="{{ route('search.index', $project) }}"
                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                検索
            </a>
            @can('viewAny', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    課題
                </a>
            @endcan
            @can('viewCalendar', $project)
                <a href="{{ route('calendar.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    カレンダー
                </a>
            @endcan
            @can('viewGantt', $project)
                <a href="{{ route('gantt.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ガントチャート
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
            @can('viewAny', [\App\Models\Repository::class, $project])
                <a href="{{ route('repository.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    リポジトリ
                </a>
            @endcan
            @can('manageMembers', $project)
                <a href="{{ route('projects.members', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    メンバー管理
                </a>
            @endcan
            @can('update', $project)
                <a href="{{ route('projects.activities', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    作業分類
                </a>
            @endcan
            @can('viewAny', [\App\Models\IssueCategory::class, $project])
                <a href="{{ route('issue-categories.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    課題カテゴリ
                </a>
            @endcan
            @can('manageVersions', [\App\Models\Version::class, $project])
                <a href="{{ route('versions.index', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    バージョン
                </a>
            @endcan
            @can('update', $project)
                <a href="{{ route('projects.edit', $project) }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    編集
                </a>
            @endcan
            @can('createSubproject', $project)
                <a href="{{ route('projects.create') }}?parent_id={{ $project->id }}"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    サブプロジェクトを追加
                </a>
            @endcan
            @can('close', $project)
                @if ($project->status === \App\Enums\ProjectStatus::Active)
                    <button wire:click="closeProject" wire:confirm="このプロジェクトをクローズしますか?"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        クローズ
                    </button>
                @elseif ($project->status === \App\Enums\ProjectStatus::Closed)
                    <button wire:click="reopenProject"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        再オープン
                    </button>
                @endif
            @endcan
            @can('archive', $project)
                @if ($project->status === \App\Enums\ProjectStatus::Archived)
                    <button wire:click="unarchiveProject"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        アーカイブ解除
                    </button>
                @else
                    <button wire:click="archiveProject" wire:confirm="このプロジェクトをアーカイブしますか?アーカイブ中は編集できなくなります。"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        アーカイブ
                    </button>
                @endif
            @endcan
        </div>
    </div>

    @if ($project->description)
        <p class="text-sm text-gray-700 mb-6">{{ $project->description }}</p>
    @endif

    @can('viewAny', [\App\Models\TimeEntry::class, $project])
        @if ($this->totalSpentHours > 0)
            <div class="rounded-md border border-gray-200 bg-white p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-900 mb-2">実績工数</h2>
                <p class="text-sm text-gray-700">{{ Number::format($this->totalSpentHours, precision: 2) }} 時間</p>
            </div>
        @endif
    @endcan

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
