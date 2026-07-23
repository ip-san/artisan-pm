{{--
    Renders the project's "Sidebar" wiki page (if one exists and has
    content) into an actual sidebar column — matches Redmine's
    Wiki#sidebar/_sidebar.html.erb. Included only on the wiki module's
    own "read" views (show/index/date-index), matching Redmine's own
    scope: not history/diff/annotate/form, and not the rest of the
    project's pages outside the wiki module.
--}}
@props(['project'])

@php
    $sidebarPage = \App\Models\WikiPage::query()
        ->where('project_id', $project->id)
        ->whereRaw('LOWER(title) = ?', ['sidebar'])
        ->with('currentVersion')
        ->first();
@endphp

@if ($sidebarPage?->currentVersion?->text)
    <aside class="w-64 shrink-0">
        <div class="prose prose-sm max-w-none rounded-md border border-gray-200 bg-white p-4">
            {!! app(\App\Support\Markdown\WikiMarkdownRenderer::class)->render($sidebarPage->currentVersion->text, $project, page: $sidebarPage) !!}
        </div>
    </aside>
@endif
