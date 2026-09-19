<x-layouts.app title="パスワードの再設定">
    <div class="mx-auto max-w-sm">
        <h1 class="text-lg font-semibold text-gray-900 mb-2">パスワードの再設定</h1>
        <p class="mb-6 text-sm text-gray-600">登録したメールアドレスを入力してください。パスワード再設定用のリンクをお送りします。</p>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">メールアドレス</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
            </div>

            <button type="submit"
                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                再設定リンクを送信
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            <a href="{{ route('login') }}" class="text-indigo-600 hover:underline">ログインに戻る</a>
        </p>
    </div>
</x-layouts.app>
