<?php

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\CustomField;
use App\Models\Setting;
use App\Concerns\ManagesPrincipalMemberships;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use App\Rules\UniqueUserValueIgnoringCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use ManagesPrincipalMemberships;

    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public bool $is_admin = false;

    public bool $must_change_passwd = false;

    public string $status = 'active';

    public ?int $auth_source_id = null;

    public string $login = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);

            $this->user = $user;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->is_admin = $user->is_admin;
            $this->must_change_passwd = $user->must_change_passwd;
            $this->status = $user->status->value;
            $this->auth_source_id = $user->auth_source_id;
            $this->login = (string) $user->login;
            $this->customFieldValues = $user->customFieldFormValues($user->relevantCustomFields());
        } else {
            $this->authorize('create', User::class);
        }
    }

    protected function membershipPrincipal(): User
    {
        return $this->user ?? abort(404);
    }

    protected function membershipColumn(): string
    {
        return 'user_id';
    }

    #[Computed]
    public function authSources(): Collection
    {
        return AuthSource::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        return ($this->user ?? new User)->relevantCustomFields();
    }

    public function save(): void
    {
        $isLdapLinked = $this->auth_source_id !== null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueUserValueIgnoringCase('email', $this->user?->id), new AllowedEmailDomain($this->user?->email)],
            'is_admin' => ['boolean'],
            'must_change_passwd' => ['boolean'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'auth_source_id' => ['nullable', 'exists:auth_sources,id'],
            // Always mandatory now, not just for LDAP-linked accounts —
            // format/length constants live on User (single source of
            // truth shared with registration and LDAP provisioning).
            'login' => ['required', 'string', 'max:'.User::LOGIN_LENGTH_LIMIT, 'regex:'.User::LOGIN_FORMAT_REGEX, new UniqueUserValueIgnoringCase('login', $this->user?->id)],
        ];

        if (! $isLdapLinked) {
            $rules['password'] = [$this->user ? 'nullable' : 'required', 'string', PasswordRule::default(), 'confirmed'];
        }

        $rules = [...$rules, ...CustomField::formValidationRules($this->customFields)];

        $data = $this->validate($rules);
        $customFieldData = CustomField::filterEditableValues($this->customFields, $data['customFieldValues'] ?? [], auth()->user());
        unset($data['customFieldValues']);

        // is_admin is intentionally not in User's Fillable list (it's a
        // privilege-granting column — see the model's docblock), so it's
        // never part of $data and is set below via direct property
        // assignment instead of mass assignment.
        $isAdmin = $data['is_admin'] ?? false;
        $mustChangePasswd = $data['must_change_passwd'] ?? false;
        unset($data['is_admin'], $data['must_change_passwd']);

        if ($isLdapLinked) {
            // Never settable through this form for an LDAP-linked account —
            // reauthenticate() always defers to the directory, so an
            // unguessable placeholder is enough to satisfy the NOT NULL
            // column on a brand-new account, matching how on-the-fly
            // LDAP provisioning already does this (AuthenticateUser).
            if (! $this->user) {
                $data['password'] = Hash::make(Str::random(40));
            }
        } else {
            $password = $data['password'] ?? '';
            unset($data['password']);

            if ($password !== '') {
                $data['password'] = Hash::make($password);
            }
        }

        if ($this->user) {
            $this->user->update($data);
        } else {
            // Matches Redmine's UserPreference seeding: a newly created
            // account starts from the admin-configured default rather
            // than the User model's own bare-column default.
            $data['no_self_notified'] = Setting::get('default_users_no_self_notified', true);
            $this->user = User::create($data);
        }

        $this->user->is_admin = $isAdmin;
        // Meaningless for a directory-backed account, whose password is not kept here.
        $this->user->must_change_passwd = ! $isLdapLinked && $mustChangePasswd;
        $this->user->save();

        $this->user->setCustomFieldValues($customFieldData);

        $this->redirect(route('users.index'), navigate: true);
    }

    /**
     * Sends Laravel's standard password-reset-link email rather than
     * setting a new password directly — matches Redmine's admin "reset
     * password and notify" action, as opposed to typing a new value into
     * the password field above.
     */
    public function sendPasswordReset(): void
    {
        $this->authorize('update', $this->user);

        abort_if($this->user->auth_source_id !== null, 400);

        Password::sendResetLink(['email' => $this->user->email]);

        session()->flash('status', 'パスワードリセットメールを送信しました。');
    }

    /**
     * Force-clears a locked-out (or otherwise unreachable) user's TOTP
     * secret and recovery codes — the admin-side counterpart to the
     * self-service disable button on the profile page, gated on the same
     * `update` permission as the rest of this form rather than the
     * self-service flow's password re-confirmation, which doesn't apply
     * when an admin is acting on someone else's account.
     */
    public function disableTwoFactor(): void
    {
        $this->authorize('update', $this->user);

        app(DisableTwoFactorAuthentication::class)($this->user);

        session()->flash('status', '二要素認証を無効にしました。');
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $user ? 'ユーザーを編集' : '新規ユーザー' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">
                {{ $auth_source_id ? 'ログインID(ディレクトリのuid)' : 'ログインID' }}
            </label>
            <input type="text" wire:model="login" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('login') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">メールアドレス</label>
            <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">ステータス</label>
            <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @foreach (\App\Enums\UserStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->value }}</option>
                @endforeach
            </select>
            @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="is_admin" class="rounded border-gray-300">
            管理者にする
        </label>

        <div>
            <label class="block text-sm font-medium text-gray-700">認証方式</label>
            <select wire:model.live="auth_source_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">ローカルパスワード</option>
                @foreach ($this->authSources as $source)
                    <option value="{{ $source->id }}">LDAP: {{ $source->name }}</option>
                @endforeach
            </select>
            @error('auth_source_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if (! $auth_source_id)
            <div>
                <label class="block text-sm font-medium text-gray-700">
                    パスワード{{ $user ? '(変更する場合のみ入力)' : '' }}
                </label>
                <input type="password" wire:model="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">パスワード(確認)</label>
                <input type="password" wire:model="password_confirmation" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="must_change_passwd" class="rounded border-gray-300">
                次回ログイン時にパスワードの変更を要求する
            </label>

            @if ($user)
                <div>
                    <button type="button" wire:click="sendPasswordReset"
                        wire:confirm="{{ $user->email }} 宛にパスワードリセットメールを送信しますか?"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        パスワードリセットメールを送信
                    </button>
                </div>
            @endif
        @endif

        @if ($user && $user->hasEnabledTwoFactorAuthentication())
            <div>
                <button type="button" wire:click="disableTwoFactor"
                    wire:confirm="{{ $user->email }} の二要素認証を無効にしますか?"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    二要素認証を無効にする
                </button>
            </div>
        @endif

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-gray-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues" :required="$field->is_required" :disabled="! $field->editableBy(auth()->user())" />
                @endforeach
            </div>
        @endif

        @if (session('status'))
            <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('users.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>

    @if ($user)
        <x-principal-memberships :memberships="$this->principalMemberships" :projects="$this->membershipProjects" :roles="$this->membershipRoles"
            :editing="$editingMembershipId ? $this->principalMemberships->firstWhere('id', $editingMembershipId) : null" />
    @endif
</div>
