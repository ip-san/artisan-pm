<?php

use App\Enums\MailNotificationOption;
use App\Rules\AllowedEmailDomain;
use App\Rules\UniqueUserValueIgnoringCase;
use App\Services\AccountDeletionService;
use App\Support\Auth\RequiresPasswordConfirmation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use App\Enums\QueryType;
use App\Models\EmailAddress;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Query as SavedQuery;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use RequiresPasswordConfirmation;

    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $code = '';

    public string $mail_notification = '';

    public string $language = '';

    public string $newAdditionalEmail = '';

    /** @var array<int, string> */
    public array $notified_project_ids = [];

    public bool $no_self_notified = false;

    public string $comments_sorting = 'asc';

    public bool $warn_on_leaving_unsaved = true;

    public string $textarea_font = '';

    public bool $hide_mail = false;

    public bool $notify_about_high_priority_issues = false;

    public int $recently_used_projects = 3;

    public string $history_default_tab = 'history';

    /** @var array<int, string> */
    public array $auto_watch_on = [];

    public ?int $default_issue_query = null;

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
        $this->mail_notification = auth()->user()->mail_notification->value;
        $this->language = (string) auth()->user()->language;
        $this->notified_project_ids = array_map('strval', auth()->user()->notifiedProjectIds());
        $this->no_self_notified = auth()->user()->no_self_notified;

        foreach (['comments_sorting', 'warn_on_leaving_unsaved', 'textarea_font', 'hide_mail', 'notify_about_high_priority_issues', 'recently_used_projects', 'history_default_tab', 'auto_watch_on', 'default_issue_query'] as $key) {
            $this->{$key} = auth()->user()->preference($key) ?? $this->{$key};
        }
    }

    /**
     * Saved issue queries the user may pick as their starting list —
     * Redmine's default_issue_query.
     *
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function issueQueries(): Collection
    {
        return SavedQuery::query()
            ->where('type', QueryType::Issue->value)
            ->whereNull('project_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (SavedQuery $query) => $query->visibleTo(auth()->user()))
            ->values();
    }

    public function savePreferences(): void
    {
        $data = $this->validate([
            'comments_sorting' => ['required', Rule::in(array_keys(UserPreferences::COMMENTS_SORTING))],
            'warn_on_leaving_unsaved' => ['boolean'],
            'textarea_font' => ['nullable', Rule::in(array_keys(UserPreferences::TEXTAREA_FONTS))],
            'hide_mail' => ['boolean'],
            'notify_about_high_priority_issues' => ['boolean'],
            'recently_used_projects' => ['required', 'integer', 'min:0', 'max:10'],
            'history_default_tab' => ['required', Rule::in(array_keys(UserPreferences::HISTORY_TABS))],
            'auto_watch_on' => ['array'],
            'auto_watch_on.*' => [Rule::in(array_keys(UserPreferences::AUTO_WATCH_ON))],
            'default_issue_query' => ['nullable', Rule::in($this->issueQueries->pluck('id')->all())],
        ]);

        UserPreferences::save(auth()->user(), $data);

        session()->flash('status', '個人設定を保存しました。');
    }

    /**
     * `selected` only makes sense for someone with a project to select; a
     * setting already saved as `selected` stays offered.
     *
     * @return array<int, MailNotificationOption>
     */
    #[Computed]
    public function notificationOptions(): array
    {
        $user = auth()->user();
        $hasProjects = $this->notifiableProjects->isNotEmpty();

        return array_values(array_filter(
            MailNotificationOption::cases(),
            fn (MailNotificationOption $option) => $option !== MailNotificationOption::Selected || $hasProjects || $user->mail_notification === MailNotificationOption::Selected,
        ));
    }

    /**
     * The projects the user belongs to directly.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function notifiableProjects(): Collection
    {
        return auth()->user()->projects()->orderBy('name')->get();
    }

    /**
     * The extra addresses this account has (plus how many more it may add).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EmailAddress>
     */
    #[Computed]
    public function additionalEmails(): \Illuminate\Database\Eloquent\Collection
    {
        return auth()->user()->additionalEmails()->get();
    }

    public function addEmail(): void
    {
        $user = auth()->user();
        $limit = (int) Setting::get('max_additional_emails', 5);

        $data = $this->validate([
            'newAdditionalEmail' => ['required', 'string', 'email', 'max:255', new UniqueUserValueIgnoringCase('email', $user->id), new AllowedEmailDomain,
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    $address = mb_strtolower((string) $value);

                    if ($address === mb_strtolower($user->email) || EmailAddress::query()->whereRaw('lower(address) = ?', [$address])->exists()) {
                        $fail('このメールアドレスは既に登録されています。');
                    }
                },
            ],
        ]);

        if ($user->additionalEmails()->count() >= $limit) {
            $this->addError('newAdditionalEmail', "追加できるメールアドレスは{$limit}件までです。");

            return;
        }

        $user->additionalEmails()->create(['address' => $data['newAdditionalEmail']]);

        $this->reset('newAdditionalEmail');
        unset($this->additionalEmails);
    }

    public function removeEmail(int $emailId): void
    {
        auth()->user()->additionalEmails()->whereKey($emailId)->delete();

        unset($this->additionalEmails);
    }

    public function toggleEmailNotify(int $emailId): void
    {
        $address = auth()->user()->additionalEmails()->whereKey($emailId)->first();

        abort_if($address === null, 404);

        $address->update(['notify' => ! $address->notify]);

        unset($this->additionalEmails);
    }

    public function updateProfile(): void
    {
        $user = auth()->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueUserValueIgnoringCase('email', $user->id), new AllowedEmailDomain($user->email)],
            'mail_notification' => ['required', Rule::in(array_map(fn (MailNotificationOption $o) => $o->value, $this->notificationOptions))],
            'notified_project_ids' => ['array'],
            'notified_project_ids.*' => [Rule::in($this->notifiableProjects->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'no_self_notified' => ['boolean'],
            'language' => ['nullable', Rule::in(array_keys(\App\Support\Locale\SupportedLocales::all()))],
        ]);
        $data['language'] = ($data['language'] ?? '') !== '' ? $data['language'] : null;

        $projectIds = $data['mail_notification'] === MailNotificationOption::Selected->value ? $data['notified_project_ids'] ?? [] : [];
        unset($data['notified_project_ids']);

        $user->update($data);
        $user->setNotifiedProjectIds($projectIds);
        $this->notified_project_ids = array_map('strval', $projectIds);

        session()->flash('status', 'プロフィールを更新しました。');
    }

    public function updatePassword(): void
    {
        $user = auth()->user();

        abort_if($user->auth_source_id !== null, 403);

        $data = $this->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_passwd' => false])->save();

        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('status', 'パスワードを変更しました。');
    }

    public function enableTwoFactor(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        app(EnableTwoFactorAuthentication::class)(auth()->user());

        unset($this->twoFactorPendingConfirmation, $this->qrCodeSvg);
    }

    public function confirmTwoFactor(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        $this->validate(['code' => ['required', 'string']]);

        try {
            app(ConfirmTwoFactorAuthentication::class)(auth()->user(), $this->code);
        } catch (ValidationException $e) {
            $this->addError('code', $e->validator->errors()->first('code'));

            return;
        }

        $this->reset('code');
        unset($this->twoFactorEnabled, $this->twoFactorPendingConfirmation, $this->recoveryCodes);
        session()->flash('status', '二要素認証を有効にしました。');
    }

    public function disableTwoFactor(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        app(DisableTwoFactorAuthentication::class)(auth()->user());

        unset($this->twoFactorEnabled, $this->twoFactorPendingConfirmation, $this->recoveryCodes);
        session()->flash('status', '二要素認証を無効にしました。');
    }

    public function regenerateRecoveryCodes(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        app(GenerateNewRecoveryCodes::class)(auth()->user());

        unset($this->recoveryCodes);
        session()->flash('status', 'リカバリーコードを再生成しました。');
    }

    /**
     * Matches Redmine's my/account "Reset" link for the API key — the key
     * itself is only ever shown right after being (re)generated, same as
     * the recovery codes above, rather than persisted anywhere readable
     * client-side across requests.
     */
    public function regenerateApiKey(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        auth()->user()->regenerateApiKey();

        unset($this->apiKey);
        session()->flash('status', 'APIキーを再生成しました。');
    }

    /**
     * Matches Redmine's MyController#destroy, which is gated by both
     * require_sudo_mode and User#own_account_deletable? (my_controller.rb:
     * 30-31, 78-89) — the sudo-mode reconfirmation happens via
     * requirePasswordConfirmation() below, own_account_deletable? via
     * User::deletable() (the `unsubscribe` setting + last-active-admin
     * check re-run here rather than trusted from a stale computed value,
     * since this is a client-tamperable Livewire action).
     */
    public function deleteAccount(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        $user = auth()->user();
        abort_unless($user->deletable(), 403);

        app(AccountDeletionService::class)->delete($user);

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('login'), navigate: false);
    }

    #[Computed]
    public function accountDeletable(): bool
    {
        return auth()->user()->deletable();
    }

    #[Computed]
    public function twoFactorEnabled(): bool
    {
        return auth()->user()->hasEnabledTwoFactorAuthentication();
    }

    #[Computed]
    public function twoFactorPendingConfirmation(): bool
    {
        $user = auth()->user();

        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
    }

    #[Computed]
    public function qrCodeSvg(): ?string
    {
        return $this->twoFactorPendingConfirmation ? auth()->user()->twoFactorQrCodeSvg() : null;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function recoveryCodes(): array
    {
        return $this->twoFactorEnabled ? auth()->user()->recoveryCodes() : [];
    }

    /**
     * Redmine's my/atom_key reset: the old key stops working at once.
     */
    public function resetAtomKey(): void
    {
        if (! $this->requirePasswordConfirmation()) {
            return;
        }

        auth()->user()->regenerateAtomKey();

        unset($this->atomKey);
        session()->flash('status', 'Atomキーをリセットしました。');
    }

    #[Computed]
    public function atomKey(): string
    {
        return auth()->user()->atomKey();
    }

    #[Computed]
    public function apiKey(): ?string
    {
        return auth()->user()->api_key;
    }
}; ?>

<div class="max-w-2xl space-y-8">
    <h1 class="text-xl font-semibold text-neutral-900">アカウント設定</h1>

    <section class="rounded-md border border-neutral-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-neutral-900">プロフィール</h2>

        <form wire:submit="updateProfile" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700">名前</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">メールアドレス</label>
                <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('email') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div data-additional-emails>
                <label class="block text-sm font-medium text-neutral-700">追加のメールアドレス</label>
                @foreach ($this->additionalEmails as $additional)
                    <div class="mt-1 flex items-center gap-3 text-sm" wire:key="additional-email-{{ $additional->id }}">
                        <span class="text-neutral-900">{{ $additional->address }}</span>
                        <button type="button" wire:click="toggleEmailNotify({{ $additional->id }})" class="text-xs {{ $additional->notify ? 'text-success-bold' : 'text-neutral-500' }} hover:underline">
                            通知{{ $additional->notify ? 'あり' : 'なし' }}
                        </button>
                        <button type="button" wire:click="removeEmail({{ $additional->id }})" wire:confirm="このメールアドレスを削除しますか?" class="text-xs text-danger-bolder hover:underline">削除</button>
                    </div>
                @endforeach
                <div class="mt-2 flex items-center gap-2">
                    <input type="email" wire:model="newAdditionalEmail" placeholder="追加するメールアドレス" class="block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <button type="button" wire:click="addEmail" class="rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50">追加</button>
                </div>
                @error('newAdditionalEmail') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">{{ __('言語') }}</label>
                <select wire:model="language" data-user-language class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('未設定(既定の言語)') }}</option>
                    @foreach (\App\Support\Locale\SupportedLocales::all() as $code => $languageName)
                        <option value="{{ $code }}">{{ $languageName }}</option>
                    @endforeach
                </select>
                @error('language') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">メール通知</label>
                <select wire:model="mail_notification" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach ($this->notificationOptions as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('mail_notification') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            @if ($mail_notification === \App\Enums\MailNotificationOption::Selected->value)
                <fieldset class="rounded-md border border-neutral-200 p-3" data-notified-projects>
                    <legend class="px-1 text-sm font-medium text-neutral-700">通知を受け取るプロジェクト</legend>
                    @foreach ($this->notifiableProjects as $notifiable)
                        <label class="flex items-center gap-2 text-sm text-neutral-700" wire:key="notified-project-{{ $notifiable->id }}">
                            <input type="checkbox" wire:model="notified_project_ids" value="{{ $notifiable->id }}" class="rounded border-neutral-300">
                            {{ $notifiable->name }}
                        </label>
                    @endforeach
                    <p class="mt-1 text-xs text-neutral-500">選択していないプロジェクトでは、自分が作成者・担当者・ウォッチャーの課題だけが通知されます。</p>
                </fieldset>
            @endif

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="no_self_notified" class="rounded border-neutral-300">
                    自分自身が行った変更については通知メールを送信しない
                </label>
            </div>

            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                保存
            </button>
        </form>
    </section>

    @if (auth()->user()->auth_source_id === null)
        <section class="rounded-md border border-neutral-200 bg-white p-4">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900">パスワード変更</h2>

            <form wire:submit="updatePassword" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-neutral-700">現在のパスワード</label>
                    <input type="password" wire:model="current_password" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('current_password') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-neutral-700">新しいパスワード</label>
                    <input type="password" wire:model="password" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('password') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-neutral-700">新しいパスワード(確認)</label>
                    <input type="password" wire:model="password_confirmation" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                </div>

                <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                    変更
                </button>
            </form>
        </section>
    @else
        <section class="rounded-md border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600">
            このアカウントはLDAP認証(「{{ auth()->user()->authSource?->name }}」)でログインしているため、
            パスワードはこのアプリからは変更できません。
        </section>
    @endif

    <section class="rounded-md border border-neutral-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-neutral-900">二要素認証</h2>

        @if ($this->twoFactorEnabled)
            <p class="mb-4 text-sm text-success-bold">二要素認証は有効です。</p>

            <div class="mb-4">
                <p class="mb-2 text-sm font-medium text-neutral-700">リカバリーコード</p>
                <ul class="grid grid-cols-2 gap-1 rounded-md bg-neutral-50 p-3 font-mono text-xs text-neutral-700">
                    @foreach ($this->recoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
                <button wire:click="regenerateRecoveryCodes" wire:confirm="リカバリーコードを再生成しますか?古いコードは無効になります。"
                    class="mt-2 text-sm text-brand-bold hover:underline">
                    再生成
                </button>
            </div>

            <button wire:click="disableTwoFactor" wire:confirm="二要素認証を無効にしますか?"
                class="rounded-md border border-danger-subtle px-4 py-2 text-sm font-medium text-danger-bolder hover:bg-danger-subtlest">
                無効にする
            </button>
        @elseif ($this->twoFactorPendingConfirmation)
            <p class="mb-4 text-sm text-neutral-600">
                認証アプリでQRコードを読み取り、表示された6桁のコードを入力して有効化を完了してください。
            </p>

            <div class="mb-4">{!! $this->qrCodeSvg !!}</div>

            <form wire:submit="confirmTwoFactor" class="flex items-end gap-3">
                <div>
                    <label class="block text-sm font-medium text-neutral-700">認証コード</label>
                    <input type="text" wire:model="code" inputmode="numeric" class="mt-1 block w-40 rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('code') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                    確認して有効化
                </button>
            </form>
        @else
            <p class="mb-4 text-sm text-neutral-600">二要素認証は無効です。</p>

            <button wire:click="enableTwoFactor" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                有効にする
            </button>
        @endif
    </section>

    <section class="rounded-md border border-neutral-200 bg-white p-4" data-preferences>
        <h2 class="mb-4 text-sm font-semibold text-neutral-900">個人設定</h2>
        <form wire:submit="savePreferences" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700">課題のコメントの並び順</label>
                <select wire:model="comments_sorting" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Preferences\UserPreferences::COMMENTS_SORTING as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('comments_sorting') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">課題の履歴の初期タブ</label>
                <select wire:model="history_default_tab" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Preferences\UserPreferences::HISTORY_TABS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">テキストエリアのフォント</label>
                <select wire:model="textarea_font" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Preferences\UserPreferences::TEXTAREA_FONTS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="warn_on_leaving_unsaved" class="rounded border-neutral-300">
                保存せずにページを離れるとき警告する
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="hide_mail" class="rounded border-neutral-300">
                メールアドレスを他のユーザーに表示しない
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="notify_about_high_priority_issues" class="rounded border-neutral-300">
                優先度が既定より高い課題は、通知設定に関わらずメールで知らせる
            </label>

            <div>
                <label class="block text-sm font-medium text-neutral-700">プロジェクト移動に表示する最近使ったプロジェクトの数</label>
                <input type="number" min="0" max="10" wire:model="recently_used_projects" class="mt-1 block w-24 rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('recently_used_projects') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700">自動的にウォッチする課題</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-neutral-700">
                    @foreach (\App\Support\Preferences\UserPreferences::AUTO_WATCH_ON as $value => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $value }}" wire:model="auto_watch_on" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">既定の課題クエリ</label>
                <select wire:model="default_issue_query" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">指定しない</option>
                    @foreach ($this->issueQueries as $query)
                        <option value="{{ $query->id }}">{{ $query->name }}</option>
                    @endforeach
                </select>
                @error('default_issue_query') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">保存</button>
        </form>
    </section>

    @if (\App\Models\Setting::get('webhooks_enabled', true) && app(\App\Support\Authorization\AuthorizationService::class)->canGlobally(auth()->user(), 'use_webhooks'))
        <section class="rounded-md border border-neutral-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-neutral-900">Webhook</h2>
            <a href="{{ route('my-webhooks.index') }}" class="text-sm text-brand-bold hover:underline">自分のWebhookを管理する</a>
        </section>
    @endif

    <section class="rounded-md border border-neutral-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-neutral-900">APIキー</h2>
        <p class="mb-4 text-sm text-neutral-600">
            スクリプトやcronジョブなど、OAuth2の認可コードフローを使わずにREST APIを呼び出したい場合に使用します。
            <code>X-Redmine-API-Key</code>ヘッダー、<code>key</code>クエリパラメータ、またはHTTP Basic認証のユーザー名として指定できます。
        </p>

        @if ($this->apiKey)
            <p class="mb-2 break-all rounded-md bg-neutral-50 p-3 font-mono text-sm text-neutral-800">{{ $this->apiKey }}</p>
        @else
            <p class="mb-2 text-sm text-neutral-500">APIキーはまだ生成されていません。</p>
        @endif

        <button wire:click="regenerateApiKey" wire:confirm="APIキーを再生成しますか?古いキーは無効になります。"
            class="text-sm text-brand-bold hover:underline">
            {{ $this->apiKey ? '再生成' : '生成' }}
        </button>
    </section>

    <section class="rounded-md border border-neutral-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-neutral-900">Atomキー</h2>
        <p class="mb-4 text-sm text-neutral-600">
            フィードリーダーなどログインできない環境からAtomフィードを購読するためのキーです。
            フィードのURLに <code>?key=</code> として付けて使います(画面上のAtomリンクには自動で付いています)。
        </p>
        <p class="mb-2 break-all rounded-md bg-neutral-50 p-3 font-mono text-sm text-neutral-800" data-atom-key>{{ $this->atomKey }}</p>
        <button wire:click="resetAtomKey" wire:confirm="Atomキーをリセットしますか?古いキーを使った購読は読めなくなります。"
            class="text-sm text-brand-bold hover:underline">
            リセット
        </button>
    </section>

    @if ($this->accountDeletable)
        <section class="rounded-md border border-danger-subtle bg-danger-subtlest p-4">
            <h2 class="mb-2 text-sm font-semibold text-danger-boldest">アカウントの削除</h2>
            <p class="mb-4 text-sm text-danger-bolder">
                アカウントを削除すると、二度と元に戻せません。参加していたすべてのプロジェクトから外れ、
                個人のウォッチ・非公開のカスタムクエリは削除されます。作成した課題・コメント・Wikiページ等はそのまま残ります。
            </p>

            <button wire:click="deleteAccount"
                wire:confirm="本当にアカウントを削除しますか?この操作は元に戻せません。"
                class="rounded-md border border-danger-subtle bg-white px-4 py-2 text-sm font-medium text-danger-bolder hover:bg-danger-subtlest">
                アカウントを削除する
            </button>
        </section>
    @endif
</div>
