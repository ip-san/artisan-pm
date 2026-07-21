<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $app_title = '';

    public int $default_issues_per_page = 25;

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        $this->app_title = Setting::get('app_title', config('app.name'));
        $this->default_issues_per_page = Setting::get('default_issues_per_page', 25);
    }

    public function save(): void
    {
        $data = $this->validate([
            'app_title' => ['required', 'string', 'max:255'],
            'default_issues_per_page' => ['required', 'integer', 'min:5', 'max:200'],
        ]);

        Setting::set('app_title', $data['app_title']);
        Setting::set('default_issues_per_page', $data['default_issues_per_page']);

        session()->flash('status', '設定を保存しました。');
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">設定</h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">アプリケーション名</label>
            <input type="text" wire:model="app_title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('app_title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">課題一覧の1ページあたりの件数</label>
            <input type="number" wire:model="default_issues_per_page" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('default_issues_per_page') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            保存
        </button>
    </form>
</div>
