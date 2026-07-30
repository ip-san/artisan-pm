<?php

use App\Models\Setting;
use App\Support\Plugins\PluginManager;
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
     * Every value is submitted as a string by the form (checkboxes excepted,
     * which Livewire already binds as real booleans) — coerced back to the
     * type of the plugin's own declared default, the same "infer the field
     * kind from the default value" simplification the form below uses to
     * decide checkbox vs. text input in the first place. This app has no
     * per-plugin custom Blade partial mechanism (Redmine's `:partial`
     * option) — every plugin gets this same generic key/value editor,
     * documented in docs/parity-checklist.md.
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
    <h1 class="mb-6 text-xl font-semibold text-gray-900">{{ $pluginName }} の設定</h1>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="max-w-lg space-y-4 rounded-md border border-gray-200 bg-white p-4">
        @foreach ($values as $key => $value)
            <div>
                @if (is_bool($value))
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="values.{{ $key }}" class="rounded border-gray-300">
                        {{ $key }}
                    </label>
                @else
                    <label class="block text-sm font-medium text-gray-700">{{ $key }}</label>
                    <input type="text" wire:model="values.{{ $key }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @endif
            </div>
        @endforeach

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            保存
        </button>
    </form>
</div>
