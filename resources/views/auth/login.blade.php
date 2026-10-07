<x-layouts.app :title="__('ログイン')">
    <div class="mx-auto max-w-sm">
        <h1 class="text-lg font-semibold text-neutral-900 mb-6">{{ __('ログイン') }}</h1>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-neutral-700">{{ __('メールアドレス') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-neutral-700">{{ __('パスワード') }}</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            @if (\App\Models\Setting::get('autologin', false))
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-neutral-600">
                        <input type="checkbox" name="remember" class="rounded border-neutral-300">
                        {{ __('ログイン状態を保持') }}
                    </label>
                </div>
            @endif

            <button type="submit"
                class="w-full btn btn-primary">
                {{ __('ログイン') }}
            </button>
        </form>

        {{-- Passkey sign-in, only where the browser supports WebAuthn; the server answers with where to go next. --}}
        <div x-data="{ error: '' }" x-show="window.ArtisanPasskeys?.supported()" x-cloak class="mt-4" data-passkey-login>
            <button type="button" class="w-full btn btn-secondary"
                x-on:click="error = ''; window.ArtisanPasskeys.login({ options: @js(route('passkey.login-options')), login: @js(route('passkey.login')) }).catch((e) => { if (e.name !== 'NotAllowedError') error = e.message; })">
                {{ __('パスキーでログイン') }}
            </button>
            <p x-show="error" x-text="error" class="mt-2 text-sm text-danger-bolder"></p>
        </div>

        @if (\App\Models\Setting::get('lost_password', true))
            <p class="mt-4 text-sm text-neutral-600">
                <a href="{{ route('password.request') }}" class="text-brand-bold hover:underline">{{ __('パスワードをお忘れの場合') }}</a>
            </p>
        @endif

        <p class="mt-4 text-sm text-neutral-600">
            {{ __('アカウントをお持ちでない場合は') }} <a href="{{ route('register') }}" class="text-brand-bold underline">{{ __('新規登録') }}</a>
        </p>
    </div>
</x-layouts.app>
