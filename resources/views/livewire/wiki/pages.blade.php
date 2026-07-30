<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * (a combined multi-page PDF isn't a zip of per-page files, so it's
     * exportPdf() below instead, not a third format here). Page titles
     * are unique per project (see wiki_pages' unique index), so there's
     * no filename collision risk inside the archive.
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

    /**
     * The whole wiki as a single combined PDF — Redmine's
     * WikiController#export format.pdf (Redmine::Export::PDF::
     * WikiPdfHelper#wiki_pages_to_pdf), which walks pages.group_by(&:
     * parent_id) to write them in hierarchical (parent, then its children,
     * depth-first) order rather than the flat title order exportZip uses
     * for its independent per-page files. Each page starts on its own PDF
     * page (page-break-before) — a readability choice, not something
     * Redmine's own hand-drawn TCPDF layout is bound by, but a reasonable
     * one here since dompdf/HTML has no equivalent to manually tracking
     * cursor position across pages.
     */
    public function exportPdf(): StreamedResponse
    {
        $this->authorize('exportAll', [WikiPage::class, $this->project]);

        $pages = $this->project->wikiPages()->with('currentVersion')->get();
        $renderer = app(WikiMarkdownRenderer::class);

        $entries = $this->hierarchicalOrder($pages)->map(fn (array $entry) => [
            'page' => $entry['page'],
            'depth' => $entry['depth'],
            'html' => $renderer->render($entry['page']->currentVersion?->text ?? '', $this->project, $entry['page']->attachments(), $entry['page']),
        ]);

        $html = view('pdf.wiki-export', [
            'project' => $this->project,
            'entries' => $entries,
        ])->render();

        $pdf = Pdf::loadHTML($html)->output();

        return response()->streamDownload(
            fn () => print ($pdf),
            "{$this->project->identifier}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Depth-first: every root page (by title), immediately followed by its
     * own children (also by title, recursively) before moving to the next
     * root — matches Redmine's pages.group_by(&:parent_id) + recursive
     * write_page_hierarchy walk.
     *
     * @param  Collection<int, WikiPage>  $pages
     * @return Collection<int, array{page: WikiPage, depth: int}>
     */
    private function hierarchicalOrder(Collection $pages): Collection
    {
        $byParent = $pages->groupBy('parent_id');
        $ordered = collect();

        $walk = function (?int $parentId, int $depth) use (&$walk, &$ordered, $byParent): void {
            foreach ($byParent->get($parentId, collect())->sortBy('title') as $page) {
                $ordered->push(['page' => $page, 'depth' => $depth]);
                $walk($page->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $ordered;
    }
}; ?>

<div class="flex items-start gap-6">
<div class="flex-1">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ $project->name }} — Wiki(タイトル順)</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('wiki.index', $project) }}" class="text-sm text-indigo-600 hover:underline">
                開始ページ
            </a>
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
                <button wire:click="exportPdf" class="text-sm text-indigo-600 hover:underline">
                    PDF
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
