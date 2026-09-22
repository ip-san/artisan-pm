<?php

use App\Models\Setting;
use App\Support\Plugins\PluginManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    // Livewire can't hydrate the Plugin value object itself across
    // requests (no Wireable support for a plain readonly class), so only
    // the display-relevant scalars are kept as component state, and $id
    // (the route param) is re-resolved through PluginManager on save().
    public string $pluginId = '';

    public string $pluginName = '';

    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(string $plugin): void
    {
        $this->authorize('manage', Setting::class);

        $manager = app(PluginManager::class);
        $found = $manager->plugin($plugin);

        abort_if($found === null || ! $manager->hasSettings($plugin), 404);

        $this->pluginId = $found->id;
        $this->pluginName = $found->name;
        $this->values = $manager->settings($plugin);
    }

    /**
     * The plugin's own Blade view for its settings (Plugin::$settingsView),
     * or null to use the generic editor — also when the named view does not
     * exist, so a typo in a plugin never breaks the page.
     */
    #[Computed]
    public function settingsView(): ?string
    {
        $view = app(PluginManager::class)->plugin($this->pluginId)?->settingsView;

        return $view !== null && view()->exists($view) ? $view : null;
    }

    /**
     * Every value is submitted as a string by the form (checkboxes excepted,
     * which Livewire already binds as real booleans) — coerced back to the
     * type of the plugin's own declared default, the same "infer the field
     * kind from the default value" simplification the form below uses to
     * decide checkbox vs. text input in the first place. A plugin with its
     * own settings view (Plugin::$settingsView) still saves through this
     * method, so its keys must exist in the declared defaults.
     */
    public function save(): void
    {
        // pluginId is public (client-tamperable) Livewire state, and this
        // method runs on every request unlike mount()'s one-time check —
        // re-authorize and re-validate the id here the same way mount()
        // does, rather than trusting whatever the client last sent.
        $this->authorize('manage', Setting::class);

        $manager = app(PluginManager::class);
        abort_if($manager->plugin($this->pluginId) === null || ! $manager->hasSettings($this->pluginId), 404);

        $defaults = $manager->settings($this->pluginId);

        $coerced = collect($defaults)
            ->keys()
            ->mapWithKeys(function (string $key) use ($defaults) {
                $submitted = $this->values[$key] ?? $defaults[$key];

                return [$key => match (true) {
                    is_bool($defaults[$key]) => (bool) $submitted,
                    is_int($defaults[$key]) => (int) $submitted,
                    default => (string) $submitted,
                }];
            })
            ->all();

        $manager->saveSettings($this->pluginId, $coerced);

        session()->flash('status', 'プラグインの設定を保存しました。');
    }
}; ?>

<div>
    <h1 class="mb-6 text-xl font-semibold text-neutral-900">{{ $pluginName }} の設定</h1>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-success-subtlest p-3 text-sm text-success-bold">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="max-w-lg space-y-4 rounded-md border border-neutral-200 bg-white p-4">
        @if ($this->settingsView)
            @include($this->settingsView, ['values' => $values, 'pluginId' => $pluginId])
        @else
            @foreach ($values as $key => $value)
                <div>
                    @if (is_bool($value))
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="values.{{ $key }}" class="rounded border-neutral-300">
                            {{ $key }}
                        </label>
                    @else
                        <label class="block text-sm font-medium text-neutral-700">{{ $key }}</label>
                        <input type="text" wire:model="values.{{ $key }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @endif
                </div>
            @endforeach
        @endif

        <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
            保存
        </button>
    </form>
</div>
