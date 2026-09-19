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
    <h1 class="text-xl font-semibold text-gray-900">情報</h1>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-900">バージョン</h2>
        <dl class="divide-y divide-gray-100 rounded-md border border-gray-200 bg-white text-sm">
            @foreach ($this->info->versions() as $label => $value)
                <div class="flex justify-between px-4 py-2"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-gray-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-900">環境</h2>
        <dl class="divide-y divide-gray-100 rounded-md border border-gray-200 bg-white text-sm">
            @foreach ($this->info->environment() as $label => $value)
                <div class="flex justify-between px-4 py-2"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-gray-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-900">チェックリスト</h2>
        <ul class="divide-y divide-gray-100 rounded-md border border-gray-200 bg-white text-sm">
            @foreach ($this->info->checks() as $check)
                <li class="flex items-center justify-between px-4 py-2" data-check="{{ $check['ok'] ? 'ok' : 'ng' }}">
                    <span class="text-gray-700">{{ $check['name'] }}</span>
                    <span class="{{ $check['ok'] ? 'text-green-700' : 'text-red-700' }}">{{ $check['ok'] ? '✔' : '✘' }} {{ $check['detail'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-900">キュー</h2>
        @php($queue = $this->info->queue())
        <dl class="divide-y divide-gray-100 rounded-md border border-gray-200 bg-white text-sm">
            <div class="flex justify-between px-4 py-2"><dt class="text-gray-500">接続</dt><dd class="text-gray-900">{{ $queue['connection'] }}</dd></div>
            @if ($queue['pending'] !== null)
                <div class="flex justify-between px-4 py-2"><dt class="text-gray-500">待機中のジョブ</dt><dd class="text-gray-900">{{ $queue['pending'] }}</dd></div>
            @endif
            @if ($queue['failed'] !== null)
                <div class="flex justify-between px-4 py-2"><dt class="text-gray-500">失敗したジョブ</dt><dd class="{{ $queue['failed'] > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $queue['failed'] }}</dd></div>
            @endif
        </dl>
        @if ($queue['connection'] === 'sync')
            <p class="mt-2 text-xs text-amber-700">同期キューです。メールやリポジトリ同期はリクエスト内で実行されます。</p>
        @endif
    </section>
</div>
