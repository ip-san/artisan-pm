{{--
    The left navigation. Inside a project it lists that project's modules (as Redmine's project
    menu does, but always in view rather than only on the overview page); elsewhere it lists the
    cross-project pages. Each entry shows only when the viewer may open it.
--}}
@php
    $user = auth()->user();
    $project = request()->route('project');
    $project = $project instanceof \App\Models\Project ? $project : null;
    $guestMenu = $user === null && ! \App\Models\Setting::get('login_required', true);

    $allows = fn (string $ability, mixed $arguments) => \Illuminate\Support\Facades\Gate::allows($ability, $arguments);
    $entry = fn (string $label, string $icon, string $url, array $routes, bool $show = true) => compact('label', 'icon', 'url', 'routes', 'show');

    if ($project !== null) {
        $sections = [
            '' => [
                $entry(__('概要'), 'overview', route('projects.show', $project), ['projects.show']),
                $entry(__('活動'), 'activity', route('activity.index', $project), ['activity.*']),
                $entry(__('課題'), 'issue', route('issues.index', $project), ['issues.*'], $allows('viewAny', [\App\Models\Issue::class, $project])),
                $entry(__('ロードマップ'), 'roadmap', route('versions.roadmap', $project), ['versions.roadmap', 'versions.show'], $allows('viewRoadmap', [\App\Models\Version::class, $project])),
                $entry(__('カレンダー'), 'calendar', route('calendar.index', $project), ['calendar.*'], $allows('viewCalendar', $project)),
                $entry(__('ガントチャート'), 'gantt', route('gantt.index', $project), ['gantt.*'], $allows('viewGantt', $project)),
                $entry(__('工数'), 'clock', route('time-entries.index', $project), ['time-entries.*'], $allows('viewAny', [\App\Models\TimeEntry::class, $project])),
                $entry('Wiki', 'wiki', route('wiki.index', $project), ['wiki.*'], $allows('viewAny', [\App\Models\WikiPage::class, $project])),
                $entry(__('フォーラム'), 'forum', route('boards.index', $project), ['boards.*', 'messages.*'], $allows('viewAny', [\App\Models\Board::class, $project])),
                $entry(__('お知らせ'), 'news', route('news.index', $project), ['news.*'], $allows('viewAny', [\App\Models\News::class, $project])),
                $entry(__('文書'), 'document', route('documents.index', $project), ['documents.*'], $allows('viewAny', [\App\Models\Document::class, $project])),
                $entry(__('ファイル'), 'file', route('files.index', $project), ['files.*'], $allows('viewAny', [\App\Models\Version::class, $project])),
                $entry(__('リポジトリ'), 'code', route('repository.index', $project), ['repository.*'], $allows('viewAny', [\App\Models\Repository::class, $project])),
            ],
            __('プロジェクトの設定') => [
                $entry(__('メンバー'), 'users', route('projects.members', $project), ['projects.members'], $allows('manageMembers', $project)),
                $entry(__('バージョン'), 'version', route('versions.index', $project), ['versions.index', 'versions.create', 'versions.edit'], $allows('manageVersions', [\App\Models\Version::class, $project])),
                $entry(__('課題カテゴリ'), 'tag', route('issue-categories.index', $project), ['issue-categories.*'], $allows('viewAny', [\App\Models\IssueCategory::class, $project])),
                $entry(__('作業分類'), 'list', route('projects.activities', $project), ['projects.activities'], $allows('update', $project)),
                $entry(__('編集'), 'settings', route('projects.edit', $project), ['projects.edit'], $allows('update', $project)),
            ],
        ];
    } elseif ($user !== null || $guestMenu) {
        $sections = [
            '' => array_merge([
                $entry(__('マイページ'), 'home', route('my-page.index'), ['my-page.*'], $user !== null),
                $entry(__('プロジェクト'), 'folder', route('projects.index'), ['projects.index', 'projects.create']),
                $entry(__('課題'), 'issue', route('issues.global-index'), ['issues.global-index']),
                $entry(__('工数'), 'clock', route('time-entries.global-index'), ['time-entries.global-index'], $user !== null),
                $entry(__('活動'), 'activity', route('activity.global-index'), ['activity.global-index']),
                $entry(__('お知らせ'), 'news', route('news.global-index'), ['news.global-index']),
                $entry(__('カレンダー'), 'calendar', route('calendar.global-index'), ['calendar.global-index']),
                $entry(__('ガントチャート'), 'gantt', route('gantt.global-index'), ['gantt.global-index']),
                $entry(__('検索'), 'search', route('search.global-index'), ['search.global-index']),
            ], $user === null ? [] : array_map(
                fn ($item) => $entry($item->label, 'plugin', $item->url, []),
                app(\App\Support\Plugins\PluginManager::class)->menuItems('nav'),
            )),
        ];
    } else {
        $sections = [];
    }

    $sections = array_filter(array_map(fn (array $items) => array_values(array_filter($items, fn (array $item) => $item['show'])), $sections));
@endphp

@if ($sections !== [])
    <nav aria-label="{{ $project !== null ? __('プロジェクトメニュー') : __('メインメニュー') }}" class="flex flex-col gap-4 text-sm" data-app-sidebar>
        @if ($project !== null)
            <div>
                <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-neutral-900">
                    <x-icon name="back" class="size-3.5" />{{ __('プロジェクト一覧') }}
                </a>
                <a href="{{ route('projects.show', $project) }}" class="mt-2 flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-surface-hovered" title="{{ $project->name }}">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-brand-subtler text-sm font-semibold text-brand-bolder" aria-hidden="true">{{ mb_substr($project->name, 0, 1) }}</span>
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-neutral-900">{{ $project->name }}</span>
                        <span class="block truncate text-xs text-neutral-500">{{ $project->identifier }}</span>
                    </span>
                </a>
            </div>
        @endif

        @foreach ($sections as $heading => $items)
            <div>
                @if ($heading !== '')
                    <p class="mb-1 px-2 text-xs font-semibold text-neutral-500">{{ $heading }}</p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($items as $item)
                        @php $active = $item['routes'] !== [] && request()->routeIs(...$item['routes']); @endphp
                        <li>
                            <a href="{{ $item['url'] }}" @if ($active) aria-current="page" @endif
                                @class([
                                    'flex items-center gap-2.5 rounded-lg px-2 py-1.5',
                                    'bg-brand-subtlest font-semibold text-brand-bolder' => $active,
                                    'text-neutral-700 hover:bg-surface-hovered hover:text-neutral-900' => ! $active,
                                ])>
                                <x-icon :name="$item['icon']" @class(['size-5', 'text-brand-bold' => $active, 'text-neutral-500' => ! $active]) />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
@endif
