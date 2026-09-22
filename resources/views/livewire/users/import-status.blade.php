<?php

use App\Enums\ImportStatus;
use App\Models\User;
use App\Models\UserImport;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public UserImport $import;

    public function mount(UserImport $import): void
    {
        $this->authorize('create', User::class);

        $this->import = $import;
    }

    public function refresh(): void
    {
        $this->import->refresh();
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">ユーザーCSVインポート状況</h1>

    <div wire:poll.2s="refresh" class="rounded-md border border-neutral-200 bg-white p-4">
        <p class="text-sm text-neutral-700 mb-2">{{ $import->original_filename }}</p>

        <div class="mb-2 h-2 w-full overflow-hidden rounded-full bg-neutral-200">
            <div class="h-2 rounded-full bg-brand-bold" style="width: {{ $import->progressPercent() }}%"></div>
        </div>

        <p class="text-sm text-neutral-600">
            @if ($import->status === ImportStatus::Pending)
                実行を待機しています…
            @elseif ($import->status === ImportStatus::Processing)
                処理中: {{ $import->processed_rows }} / {{ $import->total_rows ?? '?' }} 件
            @elseif ($import->status === ImportStatus::Completed)
                完了しました。成功 {{ $import->imported_count }} 件 / 失敗 {{ $import->failed_count }} 件
            @elseif ($import->status === ImportStatus::Failed)
                インポートに失敗しました。
            @endif
        </p>

        @if ($import->status->isFinished() && ! empty($import->errors))
            <div class="mt-4">
                <h2 class="text-sm font-semibold text-neutral-900 mb-2">エラー一覧</h2>
                <ul class="max-h-64 space-y-1 overflow-y-auto text-xs text-danger-bolder">
                    @foreach ($import->errors as $error)
                        <li>{{ $error['row'] }}行目: {{ $error['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($import->status->isFinished())
            <a href="{{ route('users.index') }}" class="mt-4 inline-block rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">ユーザー一覧へ</a>
        @endif
    </div>
</div>
