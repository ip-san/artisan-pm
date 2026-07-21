<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
<head>
    @php $appTitle = \App\Models\Setting::get('app_title', config('app.name')); @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $appTitle }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased text-gray-900">
    <div class="min-h-full">
        <nav class="bg-white border-b border-gray-200">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-14 items-center justify-between">
                    <div class="flex items-center gap-6">
                        <a href="{{ route('projects.index') }}" class="font-semibold text-gray-900">{{ $appTitle }}</a>
                        @auth
                            <a href="{{ route('my-page.index') }}" class="text-sm text-gray-600 hover:text-gray-900">マイページ</a>
                            <a href="{{ route('projects.index') }}" class="text-sm text-gray-600 hover:text-gray-900">プロジェクト</a>
                            @can('viewAny', \App\Models\Role::class)
                                <a href="{{ route('roles.index') }}" class="text-sm text-gray-600 hover:text-gray-900">ロール管理</a>
                            @endcan
                            @can('viewAny', \App\Models\Group::class)
                                <a href="{{ route('groups.index') }}" class="text-sm text-gray-600 hover:text-gray-900">グループ管理</a>
                            @endcan
                            @can('viewAny', \App\Models\CustomField::class)
                                <a href="{{ route('custom-fields.index') }}" class="text-sm text-gray-600 hover:text-gray-900">カスタムフィールド管理</a>
                            @endcan
                            @can('manage', \App\Models\Setting::class)
                                <a href="{{ route('settings.index') }}" class="text-sm text-gray-600 hover:text-gray-900">設定</a>
                            @endcan
                            @can('viewAny', \App\Models\AuthSource::class)
                                <a href="{{ route('auth-sources.index') }}" class="text-sm text-gray-600 hover:text-gray-900">LDAP認証</a>
                            @endcan
                            @can('viewAny', \App\Models\Webhook::class)
                                <a href="{{ route('webhooks.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Webhook</a>
                            @endcan
                            @foreach (app(\App\Support\Plugins\PluginManager::class)->menuItems('nav') as $item)
                                <a href="{{ $item->url }}" class="text-sm text-gray-600 hover:text-gray-900">{{ $item->label }}</a>
                            @endforeach
                        @endauth
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        @auth
                            <a href="{{ route('profile.index') }}" class="text-gray-500 hover:text-gray-900">{{ auth()->user()->name }}</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-gray-600 hover:text-gray-900">ログアウト</button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="text-gray-600 hover:text-gray-900">ログイン</a>
                            <a href="{{ route('register') }}" class="text-gray-600 hover:text-gray-900">登録</a>
                        @endauth
                    </div>
                </div>
            </div>
        </nav>

        @if (session('status'))
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-4">
                <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
            </div>
        @endif

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
