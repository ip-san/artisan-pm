<x-layouts.app title="二要素認証">
    <div class="mx-auto max-w-sm" x-data="{ useRecoveryCode: false }">
        <h1 class="text-lg font-semibold text-neutral-900 mb-2">二要素認証</h1>
        <p class="mb-6 text-sm text-neutral-600" x-show="!useRecoveryCode">
            認証アプリに表示されている6桁のコードを入力してください。
        </p>
        <p class="mb-6 text-sm text-neutral-600" x-show="useRecoveryCode" x-cloak>
            リカバリーコードを入力してください。
        </p>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
            @csrf

            <div x-show="!useRecoveryCode">
                <label for="code" class="block text-sm font-medium text-neutral-700">認証コード</label>
                <input id="code" name="code" type="text" inputmode="numeric" autofocus
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <div x-show="useRecoveryCode" x-cloak>
                <label for="recovery_code" class="block text-sm font-medium text-neutral-700">リカバリーコード</label>
                <input id="recovery_code" name="recovery_code" type="text"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm focus:border-brand focus:ring-brand sm:text-sm">
            </div>

            <button type="submit"
                class="w-full rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                認証
            </button>
        </form>

        <button type="button" x-on:click="useRecoveryCode = !useRecoveryCode" class="mt-4 text-sm text-brand-bold hover:underline">
            <span x-show="!useRecoveryCode">代わりにリカバリーコードを使う</span>
            <span x-show="useRecoveryCode" x-cloak>代わりに認証コードを使う</span>
        </button>
    </div>
</x-layouts.app>
