<?php

use App\Models\Changeset;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Changeset $changeset;

    public string $newIssueReference = '';

    /**
     * Everything the related-issues list renders per issue — project
     * included, since a linked issue may belong to another project and
     * its link is generated against that project's URL.
     *
     * @var array<int, string>
     */
    private const RELATED_ISSUE_RELATIONS = ['issues.tracker', 'issues.status', 'issues.project'];

    public function mount(Project $project, Changeset $changeset): void
    {
        $this->authorize('view', $changeset->repository);

        $this->project = $project;
        $this->changeset = $changeset->load(['files', ...self::RELATED_ISSUE_RELATIONS]);
    }

    #[Computed]
    public function canManageRelatedIssues(): bool
    {
        return (bool) auth()->user()?->can('manageRelatedIssues', [Repository::class, $this->project]);
    }

    /**
     * Manually links an issue to this changeset — matches Redmine's
     * RepositoriesController#add_related_issue: accepts "#123" or "123",
     * requires the issue to exist, be visible to the acting user, and
     * not already be linked. Like the commit-keyword auto-linker
     * (RepositorySyncService), the issue isn't restricted to this
     * project — the sync side links any referenced issue too.
     */
    public function addRelatedIssue(): void
    {
        $this->authorize('manageRelatedIssues', [Repository::class, $this->project]);

        $issueId = (int) ltrim(trim($this->newIssueReference), '#');
        $issue = $issueId > 0 ? Issue::find($issueId) : null;

        if ($issue === null || auth()->user()?->cannot('view', $issue)) {
            $this->addError('newIssueReference', '課題が見つかりません。');

            return;
        }

        if ($this->changeset->issues->contains('id', $issue->id)) {
            $this->addError('newIssueReference', 'この課題はすでに関連付けられています。');

            return;
        }

        $this->changeset->issues()->attach($issue->id);
        $this->changeset->load(self::RELATED_ISSUE_RELATIONS);
        $this->reset('newIssueReference');
    }

    public function removeRelatedIssue(int $issueId): void
    {
        $this->authorize('manageRelatedIssues', [Repository::class, $this->project]);

        $this->changeset->issues()->detach($issueId);
        $this->changeset->load(self::RELATED_ISSUE_RELATIONS);
    }

    /**
     * Diffs are immutable once a revision is committed, so this is cached
     * indefinitely rather than re-shelling out to git on every view —
     * matches the plan's requirement to cache browse/diff results instead
     * of hitting the VCS binary per request.
     */
    #[Computed]
    public function diff(): string
    {
        return Cache::rememberForever(
            "changeset:{$this->changeset->id}:diff",
            fn () => $this->changeset->repository->adapter()->diff($this->changeset->revision),
        );
    }
}; ?>

<div class="max-w-4xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
    </p>

    <h1 class="mb-1 text-xl font-semibold text-gray-900 font-mono">{{ $changeset->shortRevision() }}</h1>
    <p class="mb-4 text-sm text-gray-500">{{ $changeset->committer }} — {{ $changeset->committed_on->format('Y-m-d H:i') }}</p>

    <div class="rounded-md border border-gray-200 bg-white p-4 mb-4">
        <p class="whitespace-pre-line text-sm text-gray-800">{{ $changeset->comments }}</p>
    </div>

    @if ($changeset->issues->isNotEmpty() || $this->canManageRelatedIssues)
        <h2 class="text-sm font-semibold text-gray-900 mb-2">関連課題</h2>
        <ul class="mb-2 space-y-1">
            @forelse ($changeset->issues as $issue)
                <li class="text-sm" wire:key="related-issue-{{ $issue->id }}">
                    <a href="{{ route('issues.show', [$issue->project, $issue]) }}" class="text-indigo-600 hover:underline">
                        {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}
                    </a>
                    <span class="text-gray-500">({{ $issue->status->name }})</span>
                    @if ($this->canManageRelatedIssues)
                        <button wire:click="removeRelatedIssue({{ $issue->id }})" wire:confirm="この課題との関連付けを解除しますか?"
                            class="ml-1 text-xs text-red-600 hover:underline">解除</button>
                    @endif
                </li>
            @empty
                <li class="text-sm text-gray-500">関連付けられた課題はありません。</li>
            @endforelse
        </ul>

        @if ($this->canManageRelatedIssues)
            <form wire:submit="addRelatedIssue" class="mb-4 flex items-center gap-2">
                <input type="text" wire:model="newIssueReference" placeholder="#123"
                    class="w-28 rounded-md border-gray-300 text-sm shadow-sm">
                <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    課題を関連付け
                </button>
                @error('newIssueReference') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </form>
        @endif
    @endif

    <h2 class="text-sm font-semibold text-gray-900 mb-2">変更されたファイル ({{ $changeset->files->count() }})</h2>
    <ul class="mb-6 space-y-1">
        @foreach ($changeset->files as $file)
            <li class="font-mono text-sm">
                <span class="inline-block w-4 text-gray-500">{{ $file->action }}</span>
                {{ $file->path }}
            </li>
        @endforeach
    </ul>

    <h2 class="text-sm font-semibold text-gray-900 mb-2">差分</h2>
    <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-900 p-4 text-xs text-gray-100">{{ $this->diff }}</pre>
</div>
