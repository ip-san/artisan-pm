<?php

use App\Models\Setting;
use App\Support\System\SystemInfo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Redmine's 管理 → 情報: versions, environment and a health checklist,
 * administrators only.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('manage', Setting::class);
    }

    #[Computed]
    public function info(): SystemInfo
    {
        return app(SystemInfo::class);
    }
}; ?>

<div class="max-w-3xl space-y-8">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">情報</h1>
        <a href="{{ route('admin.default-configuration') }}" class="text-sm text-brand-bold hover:underline">デフォルト設定のロード</a>
    </div>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-neutral-900">バージョン</h2>
        <dl class="divide-y divide-neutral-100 rounded-md border border-neutral-200 bg-white text-sm">
            @foreach ($this->info->versions() as $label => $value)
                <div class="flex justify-between px-4 py-2"><dt class="text-neutral-500">{{ $label }}</dt><dd class="text-neutral-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-neutral-900">環境</h2>
        <dl class="divide-y divide-neutral-100 rounded-md border border-neutral-200 bg-white text-sm">
            @foreach ($this->info->environment() as $label => $value)
                <div class="flex justify-between px-4 py-2"><dt class="text-neutral-500">{{ $label }}</dt><dd class="text-neutral-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-neutral-900">チェックリスト</h2>
        <ul class="divide-y divide-neutral-100 rounded-md border border-neutral-200 bg-white text-sm">
            @foreach ($this->info->checks() as $check)
                <li class="flex items-center justify-between px-4 py-2" data-check="{{ $check['ok'] ? 'ok' : 'ng' }}">
                    <span class="text-neutral-700">{{ $check['name'] }}</span>
                    <span class="{{ $check['ok'] ? 'text-success-bold' : 'text-danger-bolder' }}">{{ $check['ok'] ? '✔' : '✘' }} {{ $check['detail'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-neutral-900">キュー</h2>
        @php($queue = $this->info->queue())
        <dl class="divide-y divide-neutral-100 rounded-md border border-neutral-200 bg-white text-sm">
            <div class="flex justify-between px-4 py-2"><dt class="text-neutral-500">接続</dt><dd class="text-neutral-900">{{ $queue['connection'] }}</dd></div>
            @if ($queue['pending'] !== null)
                <div class="flex justify-between px-4 py-2"><dt class="text-neutral-500">待機中のジョブ</dt><dd class="text-neutral-900">{{ $queue['pending'] }}</dd></div>
            @endif
            @if ($queue['failed'] !== null)
                <div class="flex justify-between px-4 py-2"><dt class="text-neutral-500">失敗したジョブ</dt><dd class="{{ $queue['failed'] > 0 ? 'text-danger-bolder' : 'text-neutral-900' }}">{{ $queue['failed'] }}</dd></div>
            @endif
        </dl>
        @if ($queue['connection'] === 'sync')
            <p class="mt-2 text-xs text-warning-bold">同期キューです。メールやリポジトリ同期はリクエスト内で実行されます。</p>
        @endif
    </section>
</div>
