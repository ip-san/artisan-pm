<?php

use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\IssueStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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

    public string $mail_handler_body_delimiters = '';

    public string $mail_handler_excluded_filenames = '';

    public string $mail_handler_preferred_body_part = 'plain';

    public bool $autofetch_changesets = false;

    public bool $commit_logtime_enabled = false;

    public ?int $commit_logtime_activity_id = null;

    public int $attachment_max_size = 10240;

    public string $attachment_extensions_allowed = '';

    public string $attachment_extensions_denied = '';

    public string $issue_done_ratio = 'issue_field';

    public bool $close_duplicate_issues = true;

    public bool $parent_issue_priority = true;

    public bool $parent_issue_dates = true;

    public bool $parent_issue_done_ratio = true;

    public bool $cross_project_issue_relations = false;

    public ?int $default_issue_due_date_offset = null;

    public string $self_registration = 'automatic';

    public string $email_domains_allowed = '';

    public string $email_domains_denied = '';

    public bool $default_projects_public = true;

    /** @var array<string> */
    public array $default_projects_modules = [];

    /** @var array<int> */
    public array $default_projects_tracker_ids = [];

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        $this->self_registration = Setting::get('self_registration', 'automatic');
        $this->email_domains_allowed = Setting::get('email_domains_allowed', '');
        $this->email_domains_denied = Setting::get('email_domains_denied', '');
        $this->app_title = Setting::get('app_title', config('app.name'));
        $this->default_issues_per_page = Setting::get('default_issues_per_page', 25);
        $this->issue_done_ratio = Setting::get('issue_done_ratio', 'issue_field');
        $this->close_duplicate_issues = Setting::get('close_duplicate_issues', true);
        $this->parent_issue_priority = Setting::get('parent_issue_priority', true);
        $this->parent_issue_dates = Setting::get('parent_issue_dates', true);
        $this->parent_issue_done_ratio = Setting::get('parent_issue_done_ratio', true);
        $this->cross_project_issue_relations = Setting::get('cross_project_issue_relations', false);
        $this->default_issue_due_date_offset = Setting::get('default_issue_due_date_offset');
        $this->incoming_mail_enabled = Setting::get('incoming_mail_enabled', false);
        $this->incoming_mail_default_project_id = Setting::get('incoming_mail_default_project_id');
        $this->incoming_mail_default_tracker_id = Setting::get('incoming_mail_default_tracker_id');
        $this->incoming_mail_default_status_id = Setting::get('incoming_mail_default_status_id');
        $this->mail_handler_body_delimiters = Setting::get('mail_handler_body_delimiters', '');
        $this->mail_handler_excluded_filenames = Setting::get('mail_handler_excluded_filenames', '');
        $this->mail_handler_preferred_body_part = Setting::get('mail_handler_preferred_body_part', 'plain');
        $this->autofetch_changesets = Setting::get('autofetch_changesets', false);
        $this->commit_logtime_enabled = Setting::get('commit_logtime_enabled', false);
        $this->commit_logtime_activity_id = Setting::get('commit_logtime_activity_id');
        $this->attachment_max_size = Setting::get('attachment_max_size', intdiv((int) config('media-library.max_file_size'), 1024));
        $this->attachment_extensions_allowed = Setting::get('attachment_extensions_allowed', '');
        $this->attachment_extensions_denied = Setting::get('attachment_extensions_denied', '');
        $this->default_projects_public = Setting::get('default_projects_public', true);
        $this->default_projects_modules = Setting::get(
            'default_projects_modules',
            array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults())
        );
        $this->default_projects_tracker_ids = Setting::get('default_projects_tracker_ids', []);
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

    #[Computed]
    public function activities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->orderBy('position')->get();
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
            'mail_handler_body_delimiters' => ['nullable', 'string', 'max:1000'],
            'mail_handler_excluded_filenames' => ['nullable', 'string', 'max:1000'],
            'mail_handler_preferred_body_part' => ['required', 'in:plain,html'],
            'autofetch_changesets' => ['boolean'],
            'commit_logtime_enabled' => ['boolean'],
            'commit_logtime_activity_id' => ['nullable', 'exists:enumerations,id'],
            'attachment_max_size' => ['required', 'integer', 'min:1', 'max:'.intdiv((int) config('media-library.max_file_size'), 1024)],
            'attachment_extensions_allowed' => ['nullable', 'string', 'max:1000'],
            'attachment_extensions_denied' => ['nullable', 'string', 'max:1000'],
            'issue_done_ratio' => ['required', 'in:issue_field,issue_status'],
            'close_duplicate_issues' => ['boolean'],
            'parent_issue_priority' => ['boolean'],
            'parent_issue_dates' => ['boolean'],
            'parent_issue_done_ratio' => ['boolean'],
            'cross_project_issue_relations' => ['boolean'],
            'default_issue_due_date_offset' => ['nullable', 'integer', 'min:0'],
            'self_registration' => ['required', 'in:disabled,manual,automatic'],
            'email_domains_allowed' => ['nullable', 'string', 'max:1000'],
            'email_domains_denied' => ['nullable', 'string', 'max:1000'],
            'default_projects_public' => ['boolean'],
            'default_projects_modules' => ['array'],
            'default_projects_modules.*' => [Rule::in(array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::cases()))],
            'default_projects_tracker_ids' => ['array'],
            'default_projects_tracker_ids.*' => ['exists:trackers,id'],
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

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="parent_issue_priority" class="rounded border-gray-300">
                親課題の優先度を子課題から算出する(未クローズの子課題のうち最高優先度)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="parent_issue_dates" class="rounded border-gray-300">
                親課題の開始日/期日を子課題から算出する(最も早い開始日〜最も遅い期日)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="parent_issue_done_ratio" class="rounded border-gray-300">
                親課題の進捗率を子課題から算出する(予定工数で重み付けした平均)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="cross_project_issue_relations" class="rounded border-gray-300">
                プロジェクトをまたいだ課題関連を許可する
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">新規課題の期日の既定値(作成日からの日数)</label>
                <input type="number" min="0" wire:model="default_issue_due_date_offset"
                    placeholder="未設定(既定値なし)"
                    class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">空欄の場合、期日は自動設定されません。</p>
                @error('default_issue_due_date_offset') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">プロジェクト</h2>
            <p class="text-xs text-gray-500">新規プロジェクト作成フォームの初期値です。作成時にプロジェクトごと変更できます。</p>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="default_projects_public" class="rounded border-gray-300">
                既定で公開プロジェクトにする
            </label>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">既定で有効なモジュール</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Enums\ProjectModuleKey::cases() as $module)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="default_projects_modules" value="{{ $module->value }}" class="rounded border-gray-300">
                            {{ $module->value }}
                        </label>
                    @endforeach
                </div>
                @error('default_projects_modules.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">既定で使用するトラッカー(未選択の場合は全トラッカー)</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->trackers as $tracker)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="default_projects_tracker_ids" value="{{ $tracker->id }}" class="rounded border-gray-300">
                            {{ $tracker->name }}
                        </label>
                    @endforeach
                </div>
                @error('default_projects_tracker_ids.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">認証</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">アカウント登録</label>
                <select wire:model="self_registration" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="disabled">無効(登録ページを表示しない)</option>
                    <option value="manual">管理者の承認が必要</option>
                    <option value="automatic">自動的に有効化</option>
                </select>
                @error('self_registration') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    メール確認によるアカウント有効化(Redmineの3つ目のモード)は、本アプリに送信メール基盤が無いため未対応です。
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">登録を許可するメールドメイン(カンマ区切り、空欄は制限なし)</label>
                <input type="text" wire:model="email_domains_allowed" placeholder="例: example.com, .example.org"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('email_domains_allowed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">登録を拒否するメールドメイン(カンマ区切り、許可リストより優先)</label>
                <input type="text" wire:model="email_domains_denied" placeholder="例: example.com, .example.org"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('email_domains_denied') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    先頭に「.」を付けると、そのドメインとサブドメインすべてに一致します(例: .example.org)。自己登録時のみ適用され、管理者による直接のユーザー作成には適用されません。
                </p>
            </div>
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

            <div>
                <label class="block text-sm font-medium text-gray-700">本文の取得優先形式</label>
                <select wire:model="mail_handler_preferred_body_part" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="plain">プレーンテキスト優先</option>
                    <option value="html">HTML優先(プレーンテキスト化して使用)</option>
                </select>
                @error('mail_handler_preferred_body_part') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">本文の切り捨て行(1行に1つ、この行に完全一致した箇所以降を切り捨て)</label>
                <textarea wire:model="mail_handler_body_delimiters" rows="2" placeholder="例: -----Original Message-----"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
                @error('mail_handler_body_delimiters') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">除外する添付ファイル名(カンマ区切り、ワイルドカード可)</label>
                <input type="text" wire:model="mail_handler_excluded_filenames" placeholder="例: *.ics, winmail.dat"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('mail_handler_excluded_filenames') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">リポジトリ</h2>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="autofetch_changesets" class="rounded border-gray-300">
                コミットを定期的に自動取得する(15分ごと)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="commit_logtime_enabled" class="rounded border-gray-300">
                コミットメッセージの <code>#123 @2h</code> 形式で工数を自動記録する
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">自動記録に使う作業分類(未選択の場合は既定の作業分類)</label>
                <select wire:model="commit_logtime_activity_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->activities as $activity)
                        <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                    @endforeach
                </select>
                @error('commit_logtime_activity_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            保存
        </button>
    </form>
</div>
