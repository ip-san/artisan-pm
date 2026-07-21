<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $code = '';

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
    }

    public function updateProfile(): void
    {
        $user = auth()->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->update($data);

        session()->flash('status', 'プロフィールを更新しました。');
    }

    public function updatePassword(): void
    {
        $user = auth()->user();

        abort_if($user->auth_source_id !== null, 403);

        $data = $this->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

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
     * Mirrors Illuminate\Auth\Middleware\RequirePassword's own freshness
     * check — Fortify's 2FA HTTP routes are gated by that middleware, but
     * these actions are invoked directly from Livewire rather than through
     * those routes, so the same check is replicated here.
     */
    private function requirePasswordConfirmation(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        if (now()->unix() - $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            $this->redirect(route('password.confirm'));

            return false;
        }

        return true;
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
}; ?>

<div class="max-w-2xl space-y-8">
    <h1 class="text-xl font-semibold text-gray-900">アカウント設定</h1>

    <section class="rounded-md border border-gray-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-gray-900">プロフィール</h2>

        <form wire:submit="updateProfile" class="space-y-4">
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

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
        </form>
    </section>

    @if (auth()->user()->auth_source_id === null)
        <section class="rounded-md border border-gray-200 bg-white p-4">
            <h2 class="mb-4 text-sm font-semibold text-gray-900">パスワード変更</h2>

            <form wire:submit="updatePassword" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">現在のパスワード</label>
                    <input type="password" wire:model="current_password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('current_password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">新しいパスワード</label>
                    <input type="password" wire:model="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">新しいパスワード(確認)</label>
                    <input type="password" wire:model="password_confirmation" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                </div>

                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    変更
                </button>
            </form>
        </section>
    @else
        <section class="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
            このアカウントはLDAP認証(「{{ auth()->user()->authSource?->name }}」)でログインしているため、
            パスワードはこのアプリからは変更できません。
        </section>
    @endif

    <section class="rounded-md border border-gray-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-gray-900">二要素認証</h2>

        @if ($this->twoFactorEnabled)
            <p class="mb-4 text-sm text-green-700">二要素認証は有効です。</p>

            <div class="mb-4">
                <p class="mb-2 text-sm font-medium text-gray-700">リカバリーコード</p>
                <ul class="grid grid-cols-2 gap-1 rounded-md bg-gray-50 p-3 font-mono text-xs text-gray-700">
                    @foreach ($this->recoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
                <button wire:click="regenerateRecoveryCodes" wire:confirm="リカバリーコードを再生成しますか?古いコードは無効になります。"
                    class="mt-2 text-sm text-indigo-600 hover:underline">
                    再生成
                </button>
            </div>

            <button wire:click="disableTwoFactor" wire:confirm="二要素認証を無効にしますか?"
                class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                無効にする
            </button>
        @elseif ($this->twoFactorPendingConfirmation)
            <p class="mb-4 text-sm text-gray-600">
                認証アプリでQRコードを読み取り、表示された6桁のコードを入力して有効化を完了してください。
            </p>

            <div class="mb-4">{!! $this->qrCodeSvg !!}</div>

            <form wire:submit="confirmTwoFactor" class="flex items-end gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">認証コード</label>
                    <input type="text" wire:model="code" inputmode="numeric" class="mt-1 block w-40 rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    確認して有効化
                </button>
            </form>
        @else
            <p class="mb-4 text-sm text-gray-600">二要素認証は無効です。</p>

            <button wire:click="enableTwoFactor" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                有効にする
            </button>
        @endif
    </section>
</div>
