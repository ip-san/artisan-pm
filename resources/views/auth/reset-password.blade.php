<x-layouts.app title="新しいパスワード">
    <div class="mx-auto max-w-sm">
        <h1 class="text-lg font-semibold text-neutral-900 mb-6">新しいパスワードの設定</h1>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <label for="email" class="block text-sm font-medium text-neutral-700">メールアドレス</label>
                <input id="email" name="email" type="email" value="{{ old('email', $request->query('email')) }}" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-neutral-700">新しいパスワード</label>
                <input id="password" name="password" type="password" required autofocus
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-neutral-700">新しいパスワード(確認)</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <button type="submit"
                class="w-full rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                パスワードを変更
            </button>
        </form>
    </div>
</x-layouts.app>
