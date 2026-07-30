<?php

use App\Models\Project;
use App\Models\WikiPage;
use Livewire\Volt\Component;

/**
 * Matches Redmine's WikiController#show with no :id param (the bare
 * `/projects/:id/wiki` URL) — Wiki#find_or_new_page(nil) resolves to the
 * wiki's start_page title, showing that page directly (or, if no page
 * with that title exists yet, a form to create one). This app has no
 * title-based routing (wiki.show is keyed by WikiPage id, not title — see
 * that component), so the equivalent here is a redirect to whichever of
 * wiki.show/wiki.create actually applies, rather than rendering the same
 * content at this URL directly. The page LISTING Redmine reaches via the
 * separate `/wiki/index` URL lives at wiki.pages in this app (renamed
 * from this route's own former identity, before this file became the
 * start-page redirect — see docs/parity-checklist.md's Wiki開始ページ設定
 * entry for the full rationale).
 */
new class extends Component
{
    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [WikiPage::class, $project]);

        $wiki = $project->wikiOrCreate();
        $startPage = $wiki->startPage();

        if ($startPage !== null) {
            $this->redirect(route('wiki.show', [$project, $startPage]), navigate: true);

            return;
        }

        $this->redirect(
            route('wiki.create', $project).'?'.http_build_query(['title' => $wiki->start_page]),
            navigate: true,
        );
    }
}; ?>

<div></div>
