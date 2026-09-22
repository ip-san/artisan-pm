<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Issues\SubprojectScope;
use App\Support\Auth\RequiresPasswordConfirmation;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use RequiresPasswordConfirmation;

    public Project $project;

    public string $deleteConfirmationInput = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    /**
     * This project's own logged hours — matches Redmine's project overview
     * @total_hours: this project's logged hours, plus its subprojects' when
     * display_subprojects_issues is on.
     */
    #[Computed]
    public function totalSpentHours(): float
    {
        $projects = SubprojectScope::projectsForTimeEntries($this->project, auth()->user());

        return (float) TimeEntry::query()->whereIn('project_id', $projects->pluck('id'))->sum('hours');
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
     * Matches Redmine's ProjectsController#destroy: requires sudo mode
     * (recent password confirmation) and typing the project's identifier
     * — not just a yes/no confirm — since this is an irreversible,
     * cascading deletion (kalnoy/nestedset's NodeTrait deletes the whole
     * subtree; every project-scoped table already cascades via its own
     * FK). $deleteConfirmationInput is a public, client-tamperable
     * property, so it's compared server-side here rather than trusted —
     * re-running this check is required even though the button itself is
     * only rendered behind @can('delete', ...) in the view.
     */
    public function deleteProject(): void
    {
        $this->authorize('delete', $this->project);

        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        if ($this->deleteConfirmationInput !== $this->project->identifier) {
            $this->addError('deleteConfirmationInput', '識別子が一致しません。');

            return;
        }

        $this->project->delete();

        $this->redirect(route('projects.index'), navigate: true);
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
            <h1 class="text-xl font-semibold text-neutral-900">
                {{ $project->name }}
                @unless ($project->isOpen())
                    <span class="ml-1 rounded bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 align-middle">
                        {{ $project->status === \App\Enums\ProjectStatus::Archived ? 'アーカイブ済み' : 'クローズ' }}
                    </span>
                @endunless
            </h1>
            <p class="text-sm text-neutral-500">{{ $project->identifier }}</p>
        </div>
        <div class="flex gap-2">
            <button wire:click="toggleBookmark" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ $project->isBookmarkedBy(auth()->user()) ? '★ ブックマーク解除' : '☆ ブックマーク' }}
            </button>
            <a href="{{ route('activity.index', $project) }}"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                活動
            </a>
            <a href="{{ route('search.index', $project) }}"
                class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                検索
            </a>
            @can('viewAny', [\App\Models\Issue::class, $project])
                <a href="{{ route('issues.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    課題
                </a>
            @endcan
            @can('viewCalendar', $project)
                <a href="{{ route('calendar.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    カレンダー
                </a>
            @endcan
            @can('viewGantt', $project)
                <a href="{{ route('gantt.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    ガントチャート
                </a>
            @endcan
            @can('viewAny', [\App\Models\TimeEntry::class, $project])
                <a href="{{ route('time-entries.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    工数
                </a>
            @endcan
            @can('viewAny', [\App\Models\WikiPage::class, $project])
                <a href="{{ route('wiki.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    Wiki
                </a>
            @endcan
            @can('viewAny', [\App\Models\Board::class, $project])
                <a href="{{ route('boards.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    フォーラム
                </a>
            @endcan
            @can('viewAny', [\App\Models\News::class, $project])
                <a href="{{ route('news.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    お知らせ
                </a>
            @endcan
            @can('viewAny', [\App\Models\Document::class, $project])
                <a href="{{ route('documents.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    文書
                </a>
            @endcan
            @can('viewAny', [\App\Models\Version::class, $project])
                <a href="{{ route('files.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    ファイル
                </a>
            @endcan
            @can('viewAny', [\App\Models\Repository::class, $project])
                <a href="{{ route('repository.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    リポジトリ
                </a>
            @endcan
            @can('manageMembers', $project)
                <a href="{{ route('projects.members', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    メンバー管理
                </a>
            @endcan
            @can('update', $project)
                <a href="{{ route('projects.activities', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    作業分類
                </a>
            @endcan
            @can('viewAny', [\App\Models\IssueCategory::class, $project])
                <a href="{{ route('issue-categories.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    課題カテゴリ
                </a>
            @endcan
            @can('viewRoadmap', [\App\Models\Version::class, $project])
                <a href="{{ route('versions.roadmap', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    ロードマップ
                </a>
            @endcan
            @can('manageVersions', [\App\Models\Version::class, $project])
                <a href="{{ route('versions.index', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    バージョン
                </a>
            @endcan
            @can('update', $project)
                <a href="{{ route('projects.edit', $project) }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    編集
                </a>
            @endcan
            @can('createSubproject', $project)
                <a href="{{ route('projects.create') }}?parent_id={{ $project->id }}"
                    class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    サブプロジェクトを追加
                </a>
            @endcan
            @can('close', $project)
                @if ($project->status === \App\Enums\ProjectStatus::Active)
                    <button wire:click="closeProject" wire:confirm="このプロジェクトをクローズしますか?"
                        class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                        クローズ
                    </button>
                @elseif ($project->status === \App\Enums\ProjectStatus::Closed)
                    <button wire:click="reopenProject"
                        class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                        再オープン
                    </button>
                @endif
            @endcan
            @can('archive', $project)
                @if ($project->status === \App\Enums\ProjectStatus::Archived)
                    <button wire:click="unarchiveProject"
                        class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                        アーカイブ解除
                    </button>
                @else
                    <button wire:click="archiveProject" wire:confirm="このプロジェクトをアーカイブしますか?アーカイブ中は編集できなくなります。"
                        class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                        アーカイブ
                    </button>
                @endif
            @endcan
        </div>
    </div>

    @can('delete', $project)
        <div class="mb-6 rounded-md border border-danger-subtler bg-danger-subtlest p-4">
            <h2 class="text-sm font-semibold text-danger-boldest">プロジェクトの削除</h2>
            <p class="mt-1 text-xs text-danger-bolder">
                この操作は取り消せません。課題・Wiki・バージョン等、このプロジェクトに属するすべてのデータ
                @unless ($project->isLeaf())
                    (サブプロジェクトを含む)
                @endunless
                が完全に削除されます。確認のため識別子「{{ $project->identifier }}」を入力してください。
            </p>
            <form wire:submit="deleteProject" class="mt-3 flex items-end gap-2">
                <div>
                    <input type="text" wire:model="deleteConfirmationInput" placeholder="{{ $project->identifier }}"
                        class="block rounded-md border-neutral-300 shadow-sm text-sm">
                    @error('deleteConfirmationInput') <p class="mt-1 text-xs text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:confirm="本当にこのプロジェクトを削除しますか?この操作は取り消せません。"
                    class="rounded-md bg-danger-bolder px-3 py-2 text-sm font-medium text-white hover:bg-danger-subtle">
                    削除
                </button>
            </form>
        </div>
    @endcan

    @if ($project->homepage !== null && $project->homepage !== '')
        <p class="text-sm text-neutral-700 mb-2">
            <span class="text-neutral-500">ホームページ:</span>
            @if ($project->homepageUrl())
                <a href="{{ $project->homepageUrl() }}" rel="noopener noreferrer" class="text-brand-bold hover:underline">{{ $project->homepage }}</a>
            @else
                {{ $project->homepage }}
            @endif
        </p>
    @endif

    @if ($project->description)
        <p class="text-sm text-neutral-700 mb-6">{{ $project->description }}</p>
    @endif

    @can('viewAny', [\App\Models\TimeEntry::class, $project])
        @if ($this->totalSpentHours > 0)
            <div class="rounded-md border border-neutral-200 bg-white p-4 mb-6">
                <h2 class="text-sm font-semibold text-neutral-900 mb-2">実績工数</h2>
                <p class="text-sm text-neutral-700">{{ \App\Support\Format\Hours::format($this->totalSpentHours) }} 時間</p>
            </div>
        @endif
    @endcan

    <div class="rounded-md border border-neutral-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-neutral-900 mb-2">有効なモジュール</h2>
        <div class="flex flex-wrap gap-2">
            @forelse ($project->moduleAssignments as $assignment)
                <span class="rounded bg-neutral-100 px-2 py-1 text-xs text-neutral-700">{{ $assignment->module->value }}</span>
            @empty
                <span class="text-sm text-neutral-500">有効なモジュールはありません。</span>
            @endforelse
        </div>
    </div>

    @if ($project->children->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-sm font-semibold text-neutral-900 mb-2">サブプロジェクト</h2>
            <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
                @foreach ($project->children as $child)
                    <li class="px-4 py-2">
                        <a href="{{ route('projects.show', $child) }}" class="text-brand-bold hover:underline">{{ $child->name }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
