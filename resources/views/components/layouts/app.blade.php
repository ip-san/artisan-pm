<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\Support\Preferences\UserPreferences::theme(auth()->user()) }}" class="h-full bg-neutral-50">
<head>
    @php $appTitle = \App\Models\Setting::get('app_title', config('app.name')); @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $appTitle }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased text-neutral-900">
    @php
        $currentProject = request()->route('project');
        $currentProject = $currentProject instanceof \App\Models\Project ? $currentProject : null;
        $hasSidebar = auth()->check() || ! \App\Models\Setting::get('login_required', true);
    @endphp
    <div class="min-h-full" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        {{--
            The top bar only carries what is needed from anywhere: switching project, searching, creating
            and the account. Page-to-page navigation lives in the sidebar (x-app-sidebar).
        --}}
        <header class="sticky top-0 z-30 border-b border-neutral-200 bg-surface">
            <div class="flex min-h-14 items-center gap-3 px-4 py-2 sm:px-6">
                @if ($hasSidebar)
                    <button type="button" class="-ml-1 rounded-md p-1.5 text-neutral-600 hover:bg-surface-hovered lg:hidden"
                        @click="sidebarOpen = ! sidebarOpen" :aria-expanded="sidebarOpen.toString()" aria-controls="app-sidebar" aria-label="{{ __('メニュー') }}">
                        <x-icon name="menu" />
                    </button>
                @endif
                <a href="{{ auth()->check() ? route('my-page.index') : route('projects.index') }}" class="shrink-0 font-semibold text-neutral-900">{{ $appTitle }}</a>
                @auth
                    <x-project-jump-box />
                @endauth
                @if ($hasSidebar)
                    <form method="GET" action="{{ $currentProject ? route('search.index', $currentProject) : route('search.global-index') }}" role="search" class="hidden min-w-0 flex-1 md:block md:max-w-xs">
                        <label for="app-search" class="sr-only">{{ __('検索') }}</label>
                        <div class="relative">
                            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-neutral-500" />
                            <input id="app-search" type="search" name="query" placeholder="{{ $currentProject ? __('このプロジェクトを検索') : __('検索') }}"
                                class="block w-full rounded-lg border-neutral-300 py-1.5 pl-8 text-sm">
                        </div>
                    </form>
                @endif
                <div class="ml-auto flex shrink-0 items-center gap-3 text-sm whitespace-nowrap">
                    @auth
                        @if ($currentProject)
                            <x-new-item-menu :project="$currentProject" />
                        @endif
                        <x-admin-menu />
                        <a href="{{ route('profile.index') }}" class="flex items-center gap-2 text-neutral-600 hover:text-neutral-900">
                            <x-avatar :user="auth()->user()" size="24" />
                            <span class="hidden sm:inline">{{ auth()->user()->name }}</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-neutral-600 hover:text-neutral-900">{{ __('ログアウト') }}</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-neutral-600 hover:text-neutral-900">{{ __('ログイン') }}</a>
                        <a href="{{ route('register') }}" class="text-neutral-600 hover:text-neutral-900">{{ __('登録') }}</a>
                    @endauth
                </div>
            </div>
        </header>

        <div class="flex">
            @if ($hasSidebar)
                {{-- Always shown from lg up; below that it slides over the page from the menu button. --}}
                <div x-cloak x-show="sidebarOpen" class="fixed inset-0 top-14 z-10 bg-neutral-900/30 lg:hidden" @click="sidebarOpen = false"></div>
                <aside id="app-sidebar"
                    class="fixed top-14 bottom-0 left-0 z-20 w-64 -translate-x-full overflow-y-auto border-r border-neutral-200 bg-surface p-4 transition-transform lg:sticky lg:h-[calc(100vh-3.5rem)] lg:w-60 lg:shrink-0 lg:translate-x-0 lg:bg-transparent"
                    :class="sidebarOpen && 'translate-x-0'">
                    <x-app-sidebar />
                </aside>
            @endif

            <div class="min-w-0 flex-1">
                @if (session('status'))
                    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-4">
                        <div class="rounded-md bg-success-subtlest p-3 text-sm text-success-bold">{{ session('status') }}</div>
                    </div>
                @endif

                @if (session('error'))
                    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-4">
                        <div class="rounded-md bg-danger-subtlest p-3 text-sm text-danger-bolder">{{ session('error') }}</div>
                    </div>
                @endif

                <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
