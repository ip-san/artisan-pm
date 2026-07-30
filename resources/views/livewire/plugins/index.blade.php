<?php

use App\Models\Setting;
use App\Support\Plugins\PluginManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        // Reuses SettingPolicy::manage() rather than a dedicated
        // PluginPolicy — plugin settings persist through the same
        // Setting store, so the same admin-only gate applies.
        $this->authorize('manage', Setting::class);
    }

    /**
     * @return Collection<int, \App\Support\Plugins\Plugin>
     */
    public function plugins(): Collection
    {
        return collect(app(PluginManager::class)->plugins());
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">プラグイン</h1>
        <p class="mt-1 text-sm text-gray-500">
            プラグインの追加自体はComposerパッケージと同様に手動で行います(`bootstrap/providers.php`に登録)。ここでは登録済みのプラグイン一覧と、それぞれの設定を確認・変更できます。
        </p>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->plugins() as $plugin)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-gray-900">{{ $plugin->name }}</span>
                    <span class="ml-2 text-xs text-gray-500">v{{ $plugin->version }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ $plugin->author }}</span>
                </div>
                @if (app(PluginManager::class)->hasSettings($plugin->id))
                    <a href="{{ route('plugins.settings', $plugin->id) }}" class="text-sm text-indigo-600 hover:underline">設定</a>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">登録済みのプラグインがありません。</li>
        @endforelse
    </ul>
</div>
