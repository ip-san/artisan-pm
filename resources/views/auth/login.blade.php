<x-layouts.app title="ログイン">
    <div class="mx-auto max-w-sm">
        <h1 class="text-lg font-semibold text-neutral-900 mb-6">ログイン</h1>

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
                <label for="email" class="block text-sm font-medium text-neutral-700">メールアドレス</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-neutral-700">パスワード</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            @if (\App\Models\Setting::get('autologin', false))
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-neutral-600">
                        <input type="checkbox" name="remember" class="rounded border-neutral-300">
                        ログイン状態を保持
                    </label>
                </div>
            @endif

            <button type="submit"
                class="w-full rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                ログイン
            </button>
        </form>

        @if (\App\Models\Setting::get('lost_password', true))
            <p class="mt-4 text-sm text-neutral-600">
                <a href="{{ route('password.request') }}" class="text-brand-bold hover:underline">パスワードをお忘れの場合</a>
            </p>
        @endif

        <p class="mt-4 text-sm text-neutral-600">
            アカウントをお持ちでない場合は <a href="{{ route('register') }}" class="text-brand-bold hover:underline">新規登録</a>
        </p>
    </div>
</x-layouts.app>
