<?php

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public bool $is_admin = false;

    public string $status = 'active';

    public ?int $auth_source_id = null;

    public string $login = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);

            $this->user = $user;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->is_admin = $user->is_admin;
            $this->status = $user->status->value;
            $this->auth_source_id = $user->auth_source_id;
            $this->login = (string) $user->login;
        } else {
            $this->authorize('create', User::class);
        }
    }

    #[Computed]
    public function authSources(): Collection
    {
        return AuthSource::query()->orderBy('name')->get();
    }

    public function save(): void
    {
        $isLdapLinked = $this->auth_source_id !== null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->user?->id)],
            'is_admin' => ['boolean'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'auth_source_id' => ['nullable', 'exists:auth_sources,id'],
            'login' => [$isLdapLinked ? 'required' : 'nullable', 'string', 'max:255', Rule::unique('users')->ignore($this->user?->id)],
        ];

        if (! $isLdapLinked) {
            $rules['password'] = [$this->user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'];
        }

        $data = $this->validate($rules);

        // is_admin is intentionally not in User's Fillable list (it's a
        // privilege-granting column — see the model's docblock), so it's
        // never part of $data and is set below via direct property
        // assignment instead of mass assignment.
        $isAdmin = $data['is_admin'] ?? false;
        unset($data['is_admin']);

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
            $this->user = User::create($data);
        }

        $this->user->is_admin = $isAdmin;
        $this->user->save();

        $this->redirect(route('users.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $user ? 'ユーザーを編集' : '新規ユーザー' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
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

        @if ($auth_source_id)
            <div>
                <label class="block text-sm font-medium text-gray-700">ログインID(ディレクトリのuid)</label>
                <input type="text" wire:model="login" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('login') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @else
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
</div>
