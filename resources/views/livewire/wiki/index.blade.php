<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [WikiPage::class, $project]);

        $this->project = $project;
    }

    /**
     * Root pages with their direct children eager-loaded — grandchildren
     * are reached by drilling into a child page's own show view, so the
     * index itself only ever needs two levels.
     *
     * @return Collection<int, WikiPage>
     */
    #[Computed]
    public function rootPages(): Collection
    {
        return $this->project->wikiPages()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('title')
            ->get();
    }

    /**
     * Every page in the wiki as one .txt or .html file per page, zipped
     * together — Redmine's WikiController#export, minus the PDF option
     * (would need a new rendering dependency, out of scope here). Page
     * titles are unique per project (see wiki_pages' unique index), so
     * there's no filename collision risk inside the archive.
     */
    public function exportZip(string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorize('exportAll', [WikiPage::class, $this->project]);

        abort_unless(in_array($format, ['txt', 'html'], true), 404);

        $pages = $this->project->wikiPages()->with('currentVersion')->orderBy('title')->get();

        $path = tempnam(sys_get_temp_dir(), 'wiki-export');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);

        foreach ($pages as $page) {
            $filename = Str::of($page->title)->replace(['/', '\\'], '-')->append(".{$format}")->toString();
            $zip->addFromString($filename, $this->exportedPageContent($page, $format));
        }

        $zip->close();

        return response()
            ->download($path, "{$this->project->identifier}-wiki-{$format}.zip")
            ->deleteFileAfterSend(true);
    }

    private function exportedPageContent(WikiPage $page, string $format): string
    {
        if ($format === 'txt') {
            return $page->currentVersion?->text ?? '';
        }

        $title = e($page->title);
        $body = app(WikiMarkdownRenderer::class)->render($page->currentVersion?->text ?? '', $this->project, $page->attachments(), $page);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="ja">
            <head>
            <meta charset="UTF-8">
            <title>{$title}</title>
            </head>
            <body>
            <h1>{$title}</h1>
            {$body}
            </body>
            </html>
            HTML;
    }
}; ?>

<div class="flex items-start gap-6">
<div class="flex-1">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — Wiki</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('wiki.date-index', $project) }}" class="text-sm text-indigo-600 hover:underline">
                日付順に表示
            </a>
            @can('exportAll', [WikiPage::class, $project])
                <button wire:click="exportZip('txt')" class="text-sm text-indigo-600 hover:underline">
                    ZIP(TXT)
                </button>
                <button wire:click="exportZip('html')" class="text-sm text-indigo-600 hover:underline">
                    ZIP(HTML)
                </button>
            @endcan
            @can('create', [WikiPage::class, $project])
                <a href="{{ route('wiki.create', $project) }}"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    新規ページ
                </a>
            @endcan
        </div>
    </div>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->rootPages as $page)
            <li wire:key="wiki-root-{{ $page->id }}" class="px-4 py-2">
                <a href="{{ route('wiki.show', [$project, $page]) }}" class="text-indigo-600 hover:underline">
                    {{ $page->title }}
                </a>
                @if ($page->is_protected)
                    <span class="ml-1 text-xs text-gray-400">(保護)</span>
                @endif

                @if ($page->children->isNotEmpty())
                    <ul class="mt-1 ml-4 space-y-1">
                        @foreach ($page->children->sortBy('title') as $child)
                            <li wire:key="wiki-child-{{ $child->id }}">
                                <a href="{{ route('wiki.show', [$project, $child]) }}" class="text-sm text-indigo-600 hover:underline">
                                    {{ $child->title }}
                                </a>
                                @if ($child->is_protected)
                                    <span class="ml-1 text-xs text-gray-400">(保護)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-gray-500">Wikiページがありません。</li>
        @endforelse
    </ul>
</div>

<x-wiki-sidebar :project="$project" />
</div>
