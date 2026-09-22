<x-layouts.app title="新規登録">
    <div class="mx-auto max-w-sm">
        <h1 class="text-lg font-semibold text-neutral-900 mb-6">新規登録</h1>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <div>
                <label for="login" class="block text-sm font-medium text-neutral-700">ログインID</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-neutral-700">氏名</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-neutral-700">メールアドレス</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-neutral-700">パスワード</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-neutral-700">パスワード(確認)</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            @foreach (\App\Actions\Fortify\CreateNewUser::registrationCustomFields() as $customField)
                <x-registration-custom-field :field="$customField" />
            @endforeach

            <button type="submit"
                class="w-full rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                登録
            </button>
        </form>

        <p class="mt-4 text-sm text-neutral-600">
            すでにアカウントをお持ちの場合は <a href="{{ route('login') }}" class="text-brand-bold hover:underline">ログイン</a>
        </p>
    </div>
</x-layouts.app>
