{{--
    Redmine's new_item_menu_tab, shown on any page inside a project:
      0  nothing
      1  a single "新しい課題" link
      2  a "+" dropdown with every kind of object the viewer may create there
    Each entry appears only when the viewer holds its permission.
--}}
@props(['project'])

@php
    $mode = (string) \App\Models\Setting::get('new_item_menu_tab', '2');
    $user = auth()->user();
    $can = fn (string $permission) => app(\App\Support\Authorization\AuthorizationService::class)->can($user, $permission, $project);

    $items = collect([
        ['label' => '新しい課題', 'url' => route('issues.create', $project), 'show' => $user?->can('create', [\App\Models\Issue::class, $project])],
        ['label' => '新しいカテゴリ', 'url' => route('issue-categories.create', $project), 'show' => $user?->can('create', [\App\Models\IssueCategory::class, $project])],
        ['label' => '新しいバージョン', 'url' => route('versions.create', $project), 'show' => $user?->can('create', [\App\Models\Version::class, $project])],
        ['label' => '工数を記録', 'url' => route('time-entries.create', $project), 'show' => $user?->can('create', [\App\Models\TimeEntry::class, $project])],
        ['label' => '新しいお知らせ', 'url' => route('news.create', $project), 'show' => $user?->can('create', [\App\Models\News::class, $project])],
        ['label' => '新しい文書', 'url' => route('documents.create', $project), 'show' => $user?->can('create', [\App\Models\Document::class, $project])],
        ['label' => '新しいWikiページ', 'url' => route('wiki.create', $project), 'show' => $user?->can('create', [\App\Models\WikiPage::class, $project])],
        ['label' => '新しいファイル', 'url' => route('files.index', $project), 'show' => $can('manage_files')],
    ])->filter(fn (array $item) => $item['show']);
@endphp

@if ($mode === '1')
    @if ($items->contains('label', '新しい課題'))
        <a href="{{ route('issues.create', $project) }}" class="text-sm text-neutral-600 hover:text-neutral-900" data-new-item-menu="issue">新しい課題</a>
    @endif
@elseif ($mode === '2' && $items->isNotEmpty())
    <details class="relative" data-new-item-menu="dropdown">
        <summary class="cursor-pointer list-none rounded-md border border-neutral-300 px-2 py-1 text-sm font-medium text-neutral-700 hover:bg-neutral-50" title="新規作成">+</summary>
        <ul class="absolute right-0 z-20 mt-1 w-48 rounded-md border border-neutral-200 bg-white py-1 shadow-lg">
            @foreach ($items as $item)
                <li><a href="{{ $item['url'] }}" class="block px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50">{{ $item['label'] }}</a></li>
            @endforeach
        </ul>
    </details>
@endif
