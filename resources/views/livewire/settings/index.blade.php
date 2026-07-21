<?php

use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\IssueStatus;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $app_title = '';

    public int $default_issues_per_page = 25;

    public bool $incoming_mail_enabled = false;

    public ?int $incoming_mail_default_project_id = null;

    public ?int $incoming_mail_default_tracker_id = null;

    public ?int $incoming_mail_default_status_id = null;

    public int $attachment_max_size = 10240;

    public string $attachment_extensions_allowed = '';

    public string $attachment_extensions_denied = '';

    public string $issue_done_ratio = 'issue_field';

    public bool $close_duplicate_issues = true;

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        $this->app_title = Setting::get('app_title', config('app.name'));
        $this->default_issues_per_page = Setting::get('default_issues_per_page', 25);
        $this->issue_done_ratio = Setting::get('issue_done_ratio', 'issue_field');
        $this->close_duplicate_issues = Setting::get('close_duplicate_issues', true);
        $this->incoming_mail_enabled = Setting::get('incoming_mail_enabled', false);
        $this->incoming_mail_default_project_id = Setting::get('incoming_mail_default_project_id');
        $this->incoming_mail_default_tracker_id = Setting::get('incoming_mail_default_tracker_id');
        $this->incoming_mail_default_status_id = Setting::get('incoming_mail_default_status_id');
        $this->attachment_max_size = Setting::get('attachment_max_size', intdiv((int) config('media-library.max_file_size'), 1024));
        $this->attachment_extensions_allowed = Setting::get('attachment_extensions_allowed', '');
        $this->attachment_extensions_denied = Setting::get('attachment_extensions_denied', '');
    }

    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get();
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'app_title' => ['required', 'string', 'max:255'],
            'default_issues_per_page' => ['required', 'integer', 'min:5', 'max:200'],
            'incoming_mail_enabled' => ['boolean'],
            'incoming_mail_default_project_id' => ['nullable', 'exists:projects,id'],
            'incoming_mail_default_tracker_id' => ['nullable', 'exists:trackers,id'],
            'incoming_mail_default_status_id' => ['nullable', 'exists:issue_statuses,id'],
            'attachment_max_size' => ['required', 'integer', 'min:1', 'max:'.intdiv((int) config('media-library.max_file_size'), 1024)],
            'attachment_extensions_allowed' => ['nullable', 'string', 'max:1000'],
            'attachment_extensions_denied' => ['nullable', 'string', 'max:1000'],
            'issue_done_ratio' => ['required', 'in:issue_field,issue_status'],
            'close_duplicate_issues' => ['boolean'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        session()->flash('status', '設定を保存しました。');
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">設定</h1>

    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4">
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

            <div>
                <label class="block text-sm font-medium text-gray-700">課題の進捗率</label>
                <select wire:model="issue_done_ratio" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="issue_field">課題ごとに手動入力</option>
                    <option value="issue_status">ステータスから算出</option>
                </select>
                @error('issue_done_ratio') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="close_duplicate_issues" class="rounded border-gray-300">
                重複課題を自動的にクローズする(この課題を複製とする課題がクローズされたとき)
            </label>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">添付ファイル</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">最大アップロードサイズ(KB)</label>
                <input type="number" wire:model="attachment_max_size" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attachment_max_size') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">許可する拡張子(カンマ区切り、空欄は制限なし)</label>
                <input type="text" wire:model="attachment_extensions_allowed" placeholder="例: png, jpg, pdf"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attachment_extensions_allowed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">禁止する拡張子(カンマ区切り、許可リストが設定されている場合は無視)</label>
                <input type="text" wire:model="attachment_extensions_denied" placeholder="例: exe, sh"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attachment_extensions_denied') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">メール受信による課題作成</h2>
            <p class="text-xs text-gray-500">
                接続先メールサーバーは環境変数(IMAP_HOST等)で設定します。ここでは課題の作成先を設定します。
            </p>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="incoming_mail_enabled" class="rounded border-gray-300">
                有効にする
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">
                    既定のプロジェクト(件名が <code>[識別子]</code> で始まらない場合に使用)
                </label>
                <select wire:model="incoming_mail_default_project_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_project_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">既定のトラッカー</label>
                <select wire:model="incoming_mail_default_tracker_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->trackers as $tracker)
                        <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_tracker_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">既定のステータス</label>
                <select wire:model="incoming_mail_default_status_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->statuses as $status)
                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_status_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            保存
        </button>
    </form>
</div>
