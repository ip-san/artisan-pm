<?php

use App\Models\Setting;
use App\Support\Plugins\PluginLoader;
use App\Support\Plugins\PluginManager;
use App\Support\Plugins\PluginManifest;
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
     * Plugins registered by hand in bootstrap/providers.php (a runtime
     * plugin registers itself too, so those are listed with their folder
     * instead).
     *
     * @return Collection<int, \App\Support\Plugins\Plugin>
     */
    public function plugins(): Collection
    {
        $discovered = array_keys(app(PluginLoader::class)->discovered());

        return collect(app(PluginManager::class)->plugins())
            ->reject(fn (\App\Support\Plugins\Plugin $plugin) => in_array($plugin->id, $discovered, true))
            ->values();
    }

    /**
     * The plugin folders found under the plugins path (A12-03).
     *
     * @return array<string, PluginManifest>
     */
    public function discoveredPlugins(): array
    {
        return app(PluginLoader::class)->discovered();
    }

    /**
     * Folders that couldn't be read or loaded, with the reason.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return app(PluginLoader::class)->failures();
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, PluginLoader::enabledIds(), true);
    }

    /**
     * Enables a plugin found on disk; it is loaded from the next request
     * on (the redirect below), like every other enabled plugin.
     */
    public function enable(string $id): void
    {
        $this->authorize('manage', Setting::class);

        abort_unless(array_key_exists($id, $this->discoveredPlugins()), 404);

        Setting::set('plugins_enabled', array_values(array_unique([...PluginLoader::enabledIds(), $id])));

        session()->flash('status', __('プラグイン「:name」を有効にしました。', ['name' => $this->discoveredPlugins()[$id]->name]));
        $this->redirectRoute('plugins.index');
    }

    /**
     * Disables a plugin: it is no longer loaded, so its permissions and
     * registrations are gone from the next request on (permissions already
     * granted to roles stay stored but grant nothing).
     */
    public function disable(string $id): void
    {
        $this->authorize('manage', Setting::class);

        Setting::set('plugins_enabled', array_values(array_diff(PluginLoader::enabledIds(), [$id])));

        session()->flash('status', __('プラグイン「:name」を無効にしました。', ['name' => $this->discoveredPlugins()[$id]->name ?? $id]));
        $this->redirectRoute('plugins.index');
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900">{{ __('プラグイン') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">
            {{ __('プラグインは運用者がサーバーの :path フォルダに配置します(画面からのアップロードはできません)。配置したプラグインはここで有効にするまで読み込まれません。', ['path' => 'plugins/<id>/plugin.json']) }}
        </p>
    </div>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
        @foreach ($this->discoveredPlugins() as $id => $manifest)
            @php $enabled = $this->isEnabled($id); $loaded = app(PluginLoader::class)->isLoaded($id); @endphp
            <li class="flex items-start justify-between gap-4 px-4 py-3" data-plugin="{{ $id }}">
                <div class="min-w-0">
                    <span class="font-medium text-neutral-900">{{ $manifest->name }}</span>
                    @if ($manifest->version !== '')
                        <span class="ml-2 text-xs text-neutral-500">v{{ $manifest->version }}</span>
                    @endif
                    @if ($manifest->author !== '')
                        <span class="ml-2 text-xs text-neutral-500">{{ $manifest->author }}</span>
                    @endif
                    @if ($enabled && $loaded)
                        <span class="ml-2 rounded bg-success-subtlest px-1.5 py-0.5 text-xs text-success-bold">{{ __('有効') }}</span>
                    @elseif ($enabled)
                        <span class="ml-2 rounded bg-danger-subtlest px-1.5 py-0.5 text-xs text-danger-bold">{{ __('読み込み失敗') }}</span>
                    @else
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ __('無効') }}</span>
                    @endif
                    @if ($manifest->description !== '')
                        <p class="mt-1 text-sm text-neutral-600">{{ $manifest->description }}</p>
                    @endif
                    @if ($enabled && isset($this->failures()[$id]))
                        <p class="mt-1 text-xs text-danger-bolder break-words">{{ $this->failures()[$id] }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    @if ($loaded && app(PluginManager::class)->hasSettings($id))
                        <a href="{{ route('plugins.settings', $id) }}" class="text-sm text-brand-bold hover:underline">{{ __('設定') }}</a>
                    @endif
                    @if ($enabled)
                        <button type="button" wire:click="disable('{{ $id }}')" wire:confirm="{{ __('このプラグインを無効にしますか?') }}" class="text-sm text-danger-bold hover:underline">{{ __('無効にする') }}</button>
                    @else
                        <button type="button" wire:click="enable('{{ $id }}')" class="text-sm text-brand-bold hover:underline">{{ __('有効にする') }}</button>
                    @endif
                </div>
            </li>
        @endforeach

        @foreach ($this->plugins() as $plugin)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $plugin->name }}</span>
                    <span class="ml-2 text-xs text-neutral-500">v{{ $plugin->version }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ $plugin->author }}</span>
                </div>
                @if (app(PluginManager::class)->hasSettings($plugin->id))
                    <a href="{{ route('plugins.settings', $plugin->id) }}" class="text-sm text-brand-bold hover:underline">{{ __('設定') }}</a>
                @endif
            </li>
        @endforeach

        @if ($this->discoveredPlugins() === [] && $this->plugins()->isEmpty())
            <li class="px-4 py-6 text-sm text-neutral-500">{{ __('登録済みのプラグインがありません。') }}</li>
        @endif
    </ul>

    @php $invalid = array_diff_key($this->failures(), $this->discoveredPlugins()); @endphp
    @if ($invalid !== [])
        <h2 class="mt-8 mb-2 text-sm font-semibold text-neutral-900">{{ __('読み込めないプラグイン') }}</h2>
        <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface" data-invalid-plugins>
            @foreach ($invalid as $folder => $reason)
                <li class="px-4 py-3">
                    <span class="font-mono text-sm text-neutral-900">plugins/{{ $folder }}</span>
                    <p class="mt-1 text-xs text-danger-bolder break-words">{{ $reason }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
