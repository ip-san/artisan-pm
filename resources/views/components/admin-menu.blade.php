{{--
    Redmine keeps a single "Administration" entry in its top menu; the admin screens sit behind it.
    Listing them all in the header pushed half the menu off-screen.
--}}
@php
    $user = auth()->user();

    $items = collect([
        ['label' => __('ロール管理'), 'url' => route('roles.index'), 'show' => $user->can('viewAny', \App\Models\Role::class)],
        ['label' => __('グループ管理'), 'url' => route('groups.index'), 'show' => $user->can('viewAny', \App\Models\Group::class)],
        ['label' => __('カスタムフィールド管理'), 'url' => route('custom-fields.index'), 'show' => $user->can('viewAny', \App\Models\CustomField::class)],
        ['label' => __('プロジェクト管理'), 'url' => route('admin.projects'), 'show' => $user->can('manage', \App\Models\Setting::class)],
        ['label' => __('設定'), 'url' => route('settings.index'), 'show' => $user->can('manage', \App\Models\Setting::class)],
        ['label' => __('プラグイン'), 'url' => route('plugins.index'), 'show' => $user->can('manage', \App\Models\Setting::class)],
        ['label' => __('情報'), 'url' => route('admin.info'), 'show' => $user->can('manage', \App\Models\Setting::class)],
        ['label' => __('LDAP認証'), 'url' => route('auth-sources.index'), 'show' => $user->can('viewAny', \App\Models\AuthSource::class)],
        ['label' => 'Webhook', 'url' => route('webhooks.index'), 'show' => $user->can('viewAny', \App\Models\Webhook::class)],
        ['label' => __('ユーザー管理'), 'url' => route('users.index'), 'show' => $user->can('viewAny', \App\Models\User::class)],
        ['label' => __('トラッカー管理'), 'url' => route('trackers.index'), 'show' => $user->can('viewAny', \App\Models\Tracker::class)],
        ['label' => __('ステータス管理'), 'url' => route('issue-statuses.index'), 'show' => $user->can('viewAny', \App\Models\IssueStatus::class)],
        ['label' => __('ワークフロー管理'), 'url' => route('workflows.edit'), 'show' => $user->can('manage', \App\Models\WorkflowTransition::class)],
        ['label' => __('値の一覧'), 'url' => route('enumerations.index', \App\Enums\EnumerationType::IssuePriority->value), 'show' => $user->can('viewAny', \App\Models\Enumeration::class)],
    ])->filter(fn (array $item) => $item['show']);
@endphp

@if ($items->isNotEmpty())
    <details class="relative" data-admin-menu>
        <summary class="cursor-pointer list-none text-neutral-600 hover:text-neutral-900">{{ __('管理') }}</summary>
        <ul class="absolute right-0 z-20 mt-2 w-56 rounded-md border border-neutral-200 bg-surface py-1 shadow-lg">
            @foreach ($items as $item)
                <li><a href="{{ $item['url'] }}" class="block px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50">{{ $item['label'] }}</a></li>
            @endforeach
        </ul>
    </details>
@endif
