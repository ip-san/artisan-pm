<x-layouts.app title="パスワード確認">
    <div class="mx-auto max-w-sm">
        <h1 class="text-lg font-semibold text-gray-900 mb-2">パスワード確認</h1>
        <p class="mb-6 text-sm text-gray-600">これは重要な操作です。続行する前にパスワードを確認してください。</p>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
            @csrf

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">パスワード</label>
                <input id="password" name="password" type="password" required autofocus
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
            </div>

            <button type="submit"
                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                確認
            </button>
        </form>
    </div>
</x-layouts.app>
