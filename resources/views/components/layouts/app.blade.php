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
    <div class="min-h-full">
        <nav class="bg-surface border-b border-neutral-200">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex min-h-14 items-center justify-between gap-4 py-2">
                    {{--
                        Admin screens live in the x-admin-menu dropdown so this row fits a desktop width.
                        whitespace-nowrap keeps each Japanese label (no spaces to break on) on one line; on a
                        narrow window the row wraps rather than scrolls, because a scrolling row would clip the
                        project jump box's dropdown panel.
                    --}}
                    <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2 whitespace-nowrap">
                        <a href="{{ route('projects.index') }}" class="font-semibold text-neutral-900">{{ $appTitle }}</a>
                        @auth
                            <x-project-jump-box />
                            <a href="{{ route('my-page.index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('マイページ') }}</a>
                            <a href="{{ route('projects.index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('プロジェクト') }}</a>
                            <a href="{{ route('issues.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('課題') }}</a>
                            <a href="{{ route('time-entries.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('工数') }}</a>
                            <a href="{{ route('news.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('お知らせ') }}</a>
                            <a href="{{ route('calendar.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('カレンダー') }}</a>
                            <a href="{{ route('gantt.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('ガントチャート') }}</a>
                            <a href="{{ route('activity.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('活動') }}</a>
                            <a href="{{ route('search.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('検索') }}</a>
                            @foreach (app(\App\Support\Plugins\PluginManager::class)->menuItems('nav') as $item)
                                <a href="{{ $item->url }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ $item->label }}</a>
                            @endforeach
                        @else
                            {{-- With login_required off a guest gets the menu of the pages open to them (A1-44); each page shows what the Anonymous role allows. --}}
                            @unless (\App\Models\Setting::get('login_required', true))
                                <a href="{{ route('projects.index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('プロジェクト') }}</a>
                                <a href="{{ route('issues.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('課題') }}</a>
                                <a href="{{ route('calendar.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('カレンダー') }}</a>
                                <a href="{{ route('gantt.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('ガントチャート') }}</a>
                                <a href="{{ route('activity.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('活動') }}</a>
                                <a href="{{ route('news.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('お知らせ') }}</a>
                                <a href="{{ route('search.global-index') }}" class="text-sm text-neutral-600 hover:text-neutral-900">{{ __('検索') }}</a>
                            @endunless
                        @endauth
                    </div>
                    <div class="flex shrink-0 items-center gap-4 text-sm whitespace-nowrap">
                        @auth
                            @if (($currentProject = request()->route('project')) instanceof \App\Models\Project)
                                <x-new-item-menu :project="$currentProject" />
                            @endif
                            <x-admin-menu />
                            <a href="{{ route('profile.index') }}" class="text-neutral-500 hover:text-neutral-900">{{ auth()->user()->name }}</a>
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
            </div>
        </nav>

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

    @livewireScripts
</body>
</html>
