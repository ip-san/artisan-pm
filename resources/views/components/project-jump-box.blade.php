{{--
    Redmine's project jump box: a header dropdown with incremental search over
    the recently used projects, the bookmarked ones and every project the user
    belongs to.
--}}
@php
    $groups = \App\Support\Preferences\ProjectJumpBox::entries(auth()->user());
    $current = request()->route('project');
    $sections = [
        '最近使ったプロジェクト' => $groups['recent'],
        'ブックマーク' => $groups['bookmarked'],
        'すべてのプロジェクト' => $groups['all'],
    ];
@endphp

@if ($groups['all']->isNotEmpty() || $groups['bookmarked']->isNotEmpty())
    <details class="relative" data-project-jump-box x-data="{ q: '' }">
        <summary class="cursor-pointer list-none rounded-md border border-gray-300 px-2 py-1 text-sm text-gray-700 hover:bg-gray-50">
            {{ $current instanceof \App\Models\Project ? $current->name : 'プロジェクトへ移動' }}
        </summary>
        <div class="absolute left-0 z-20 mt-1 w-64 rounded-md border border-gray-200 bg-white p-2 shadow-lg">
            <input type="text" x-model="q" placeholder="プロジェクトを検索" autocomplete="off"
                class="mb-2 block w-full rounded-md border-gray-300 text-sm shadow-sm">
            <div class="max-h-72 overflow-y-auto text-sm">
                @foreach ($sections as $label => $projects)
                    @if ($projects->isNotEmpty())
                        <p class="mt-1 px-2 text-xs font-semibold text-gray-400">{{ $label }}</p>
                        <ul>
                            @foreach ($projects as $project)
                                <li x-show="{{ \Illuminate\Support\Js::from(mb_strtolower($project->name)) }}.includes(q.toLowerCase())">
                                    <a href="{{ route('projects.show', $project) }}" class="block rounded px-2 py-1 text-gray-700 hover:bg-gray-50">{{ $project->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            </div>
            <a href="{{ route('projects.index') }}" class="mt-2 block border-t border-gray-100 px-2 pt-2 text-sm text-indigo-600 hover:underline">プロジェクト一覧</a>
        </div>
    </details>
@endif
