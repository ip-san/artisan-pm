<?php

use App\Enums\ImportStatus;
use App\Models\Issue;
use App\Models\IssueImport;
use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public IssueImport $import;

    public function mount(Project $project, IssueImport $import): void
    {
        $this->authorize('create', [Issue::class, $project]);
        abort_unless($import->project_id === $project->id, 404);

        $this->project = $project;
        $this->import = $import;
    }

    public function refresh(): void
    {
        $this->import->refresh();
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ $project->name }} — {{ __('CSVインポート状況') }}</h1>

    <div @if (! $import->status->isFinished()) wire:poll.5s="refresh" @endif class="rounded-md border border-neutral-200 bg-surface p-4">
        <p class="text-sm text-neutral-700 mb-2">{{ $import->original_filename }}</p>

        <div class="mb-2 h-2 w-full overflow-hidden rounded-full bg-neutral-200">
            <div class="h-2 rounded-full bg-brand-bold" style="width: {{ $import->progressPercent() }}%"></div>
        </div>

        <p class="text-sm text-neutral-600">
            @if ($import->status === ImportStatus::Pending)
                {{ __('実行を待機しています…') }}
            @elseif ($import->status === ImportStatus::Processing)
                {{ __('処理中: :processed / :total 件', ['processed' => $import->processed_rows, 'total' => $import->total_rows ?? '?']) }}
            @elseif ($import->status === ImportStatus::Completed)
                {{ __('完了しました。成功 :imported 件 / 失敗 :failed 件', ['imported' => $import->imported_count, 'failed' => $import->failed_count]) }}
            @elseif ($import->status === ImportStatus::Failed)
                {{ __('インポートに失敗しました。') }}
            @endif
        </p>

        @if ($import->status->isFinished() && ! empty($import->errors))
            <div class="mt-4">
                <h2 class="text-sm font-semibold text-neutral-900 mb-2">{{ __('エラー一覧') }}</h2>
                <ul class="max-h-64 space-y-1 overflow-y-auto text-xs text-danger-bolder">
                    @foreach ($import->errors as $error)
                        <li>{{ __(':row行目: :message', ['row' => $error['row'], 'message' => $error['message']]) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($import->status->isFinished())
            <a href="{{ route('issues.index', $project) }}" class="mt-4 inline-block rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('課題一覧へ') }}
            </a>
        @endif
    </div>
</div>
