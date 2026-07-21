<?php

use App\Models\Changeset;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Changeset $changeset;

    public function mount(Project $project, Changeset $changeset): void
    {
        $this->authorize('view', $changeset->repository);

        $this->project = $project;
        $this->changeset = $changeset->load(['files', 'issues.tracker', 'issues.status']);
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

    @if ($changeset->issues->isNotEmpty())
        <h2 class="text-sm font-semibold text-gray-900 mb-2">関連課題</h2>
        <ul class="mb-4 space-y-1">
            @foreach ($changeset->issues as $issue)
                <li class="text-sm">
                    <a href="{{ route('issues.show', [$project, $issue]) }}" class="text-indigo-600 hover:underline">
                        {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}
                    </a>
                    <span class="text-gray-500">({{ $issue->status->name }})</span>
                </li>
            @endforeach
        </ul>
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
