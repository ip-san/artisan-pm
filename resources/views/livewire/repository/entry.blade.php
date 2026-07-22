<?php

use App\Models\Project;
use App\Models\Repository;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $path;

    public function mount(Project $project, string $path): void
    {
        $this->authorize('browse', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
        $this->path = trim($path, '/');
    }

    #[Computed]
    public function content(): string
    {
        return $this->repository->adapter()->fileContentAt('HEAD', $this->path);
    }

    #[Computed]
    public function isBinary(): bool
    {
        return ! mb_check_encoding($this->content, 'UTF-8');
    }

    #[Computed]
    public function directoryPath(): string
    {
        $parts = explode('/', $this->path);
        array_pop($parts);

        return implode('/', $parts);
    }
}; ?>

<div>
    <div class="mb-6">
        <p class="text-sm text-gray-500">
            <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
            /
            <a href="{{ route('repository.browse', [$project, $this->directoryPath]) }}" class="text-indigo-600 hover:underline">
                ファイル一覧
            </a>
        </p>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900 font-mono">{{ $path }}</h1>
            <div class="flex gap-2">
                @unless ($this->isBinary)
                    <a href="{{ route('repository.annotate', [$project, $path]) }}"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        注釈
                    </a>
                @endunless
                <a href="{{ route('repository.file-history', [$project, $path]) }}"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    履歴
                </a>
                <a href="{{ route('repository.raw', [$project, $path]) }}"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ダウンロード
                </a>
            </div>
        </div>
    </div>

    @if ($this->isBinary)
        <p class="text-sm text-gray-500">バイナリファイルは表示できません。上の「ダウンロード」から取得してください。</p>
    @else
        <pre class="overflow-x-auto rounded-md border border-gray-200 bg-gray-900 p-4 text-xs text-gray-100">{{ $this->content }}</pre>
    @endif
</div>
