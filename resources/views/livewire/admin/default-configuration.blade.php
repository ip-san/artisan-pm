<?php

use App\Models\Setting;
use Database\Seeders\DefaultConfigurationSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Redmine's 管理 → デフォルト設定のロード: puts the standard roles,
 * trackers, statuses, priorities, activities and workflow in place on an
 * installation that has none. Administrators only, and only while nothing
 * of that kind exists yet.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public string $locale = 'ja';

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);
    }

    #[Computed]
    public function configured(): bool
    {
        return DefaultConfigurationSeeder::isConfigured();
    }

    public function load(): void
    {
        $this->authorize('manage', Setting::class);

        $this->validate(['locale' => ['required', 'in:'.implode(',', array_keys(DefaultConfigurationSeeder::NAMES))]]);

        if (DefaultConfigurationSeeder::isConfigured()) {
            $this->addError('locale', 'すでにトラッカー・ステータス・優先度・ロールが登録されているため、読み込めません。');

            return;
        }

        DB::transaction(fn () => (new DefaultConfigurationSeeder($this->locale))->run());

        unset($this->configured);
        session()->flash('status', 'デフォルト設定を読み込みました。');
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="mb-6 text-xl font-semibold text-neutral-900">デフォルト設定のロード</h1>

    <p class="mb-4 text-sm text-neutral-600">
        標準のロール(マネージャー・開発者・報告者)、トラッカー、課題ステータス、優先度、作業分類、ワークフローを登録します。
    </p>

    @if ($this->configured)
        <p class="rounded-md bg-warning-subtlest p-3 text-sm text-warning-bolder" data-default-configuration="loaded">
            すでに設定があるため、読み込みは行えません。
        </p>
    @else
        <form wire:submit="load" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700">言語</label>
                <select wire:model="locale" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="ja">日本語</option>
                    <option value="en">English</option>
                </select>
                @error('locale') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                デフォルト設定を読み込む
            </button>
        </form>
    @endif
</div>
