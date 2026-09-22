<?php

use App\Models\Project;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Wiki\WikiExportFilename;
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
     * Deletes every page of this project's wiki, with its versions,
     * attachments and watchers — Redmine's WikisController#destroy. Each
     * page goes through the normal delete path so webhooks fire for it.
     */
    public function deleteWiki(): void
    {
        $this->authorize('destroyWiki', [WikiPage::class, $this->project]);

        $service = app(WikiPageService::class);

        // Children are detached when their parent goes, so this walks one
        // page at a time until none is left.
        while (($page = $this->project->wikiPages()->first()) !== null) {
            $service->delete($page);
        }

        session()->flash('status', 'Wikiを削除しました。');

        $this->redirect(route('projects.show', $this->project), navigate: true);
    }

    /**
     * Every page in the wiki as one .txt or .html file per page, zipped
     * together — Redmine's WikiController#export, minus the PDF option
     * (a combined multi-page PDF isn't a zip of per-page files, so it's
     * exportPdf() below instead, not a third format here). Titles are
     * unique per project, but two can still map to the same file name
     * once sanitized, so those get a (1), (2)… suffix like Redmine's.
     */
    public function exportZip(string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorize('exportAll', [WikiPage::class, $this->project]);

        abort_unless(in_array($format, ['txt', 'html'], true), 404);

        $pages = $this->project->wikiPages()->with('currentVersion')->orderBy('title')->get();

        $path = tempnam(sys_get_temp_dir(), 'wiki-export');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);

        $usedNames = [];

        foreach ($pages as $page) {
            $filename = WikiExportFilename::for($page->title, $format, $usedNames);
            $usedNames[] = $filename;
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
        <h1 class="text-xl font-semibold text-neutral-900">{{ $project->name }} — Wiki(タイトル順)</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('wiki.index', $project) }}" class="text-sm text-brand-bold hover:underline">
                開始ページ
            </a>
            <a href="{{ route('wiki.date-index', $project) }}" class="text-sm text-brand-bold hover:underline">
                日付順に表示
            </a>
            @can('exportAll', [WikiPage::class, $project])
                <button wire:click="exportZip('txt')" class="text-sm text-brand-bold hover:underline">
                    ZIP(TXT)
                </button>
                <button wire:click="exportZip('html')" class="text-sm text-brand-bold hover:underline">
                    ZIP(HTML)
                </button>
                <button wire:click="exportPdf" class="text-sm text-brand-bold hover:underline">
                    PDF
                </button>
            @endcan
            @can('destroyWiki', [WikiPage::class, $project])
                <button wire:click="deleteWiki" wire:confirm="このプロジェクトのWikiを、すべてのページ・履歴・添付ファイルごと削除します。この操作は取り消せません。よろしいですか?"
                    class="text-sm text-danger-bolder hover:underline">
                    Wikiを削除
                </button>
            @endcan
            @can('create', [WikiPage::class, $project])
                <a href="{{ route('wiki.create', $project) }}"
                    class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                    新規ページ
                </a>
            @endcan
        </div>
    </div>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->rootPages as $page)
            <li wire:key="wiki-root-{{ $page->id }}" class="px-4 py-2">
                <a href="{{ route('wiki.show', [$project, $page]) }}" class="text-brand-bold hover:underline">
                    {{ $page->title }}
                </a>
                @if ($page->is_protected)
                    <span class="ml-1 text-xs text-neutral-400">(保護)</span>
                @endif

                @if ($page->children->isNotEmpty())
                    <ul class="mt-1 ml-4 space-y-1">
                        @foreach ($page->children->sortBy('title') as $child)
                            <li wire:key="wiki-child-{{ $child->id }}">
                                <a href="{{ route('wiki.show', [$project, $child]) }}" class="text-sm text-brand-bold hover:underline">
                                    {{ $child->title }}
                                </a>
                                @if ($child->is_protected)
                                    <span class="ml-1 text-xs text-neutral-400">(保護)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-neutral-500">Wikiページがありません。</li>
        @endforelse
    </ul>
</div>

<x-wiki-sidebar :project="$project" />
</div>
