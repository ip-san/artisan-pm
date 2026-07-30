<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Markdown\WikiMarkdownRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matches Redmine's IssuesController#show responding to format.pdf
 * (Redmine::Export::PDF::IssuesPdfHelper#issue_to_pdf). Redmine builds its
 * PDF by hand with TCPDF drawing primitives (cells, coordinates, page
 * breaks); this instead renders a print-styled Blade view through
 * barryvdh/laravel-dompdf (HTML/CSS -> PDF) — a deliberate implementation
 * simplification (same "generic renderer over bespoke per-case drawing
 * code" trade-off this app already made for the plugin settings UI), not
 * a scope cut: the same fields Redmine's issue_to_pdf prints (core
 * attributes, description, custom fields, notes) are all present here.
 *
 * Returns dompdf's Response directly rather than going through
 * response()->streamDownload() the way WikiPage's PDF export does —
 * safe here specifically because this is a plain controller reached by
 * a real `<a href>` GET, never a Livewire action. A binary Response body
 * returned from *inside* a Livewire component action breaks Livewire's
 * own AJAX response serialization (confirmed empirically while building
 * the wiki export), which is why that path needs streamDownload() and
 * this one doesn't.
 */
final class IssuePdfController extends Controller
{
    public function __invoke(Project $project, Issue $issue): Response
    {
        Gate::authorize('view', $issue);

        $issue->load([
            'tracker', 'status', 'priority', 'category', 'author', 'assignedTo',
            'fixedVersion', 'parent', 'customFieldValues', 'journals.user',
        ]);

        $renderer = app(WikiMarkdownRenderer::class);

        $html = view('pdf.issue', [
            'issue' => $issue,
            'project' => $project,
            'descriptionHtml' => $issue->description !== null
                ? $renderer->render($issue->description, $project, $issue->attachments())
                : null,
            'customFieldValues' => $issue->relevantCustomFields()->map(fn (CustomField $field) => [
                'field' => $field,
                'value' => $field->multiple
                    ? $issue->customFieldValues->where('custom_field_id', $field->id)->map(fn ($v) => $v->value())->join(', ')
                    : $issue->customValue($field),
            ]),
            // Matches issues.show's own visibleJournals: a private note is
            // visible to its own author even though nobody else on the
            // project sees it — the PDF export shouldn't be a way to see
            // less than the page it's exported from.
            'notes' => $issue->journals
                ->filter(fn ($journal) => filled($journal->notes))
                ->filter(fn ($journal) => ! $journal->private_notes || $journal->user_id === auth()->id())
                ->map(fn ($journal) => [
                    'journal' => $journal,
                    'html' => $renderer->render((string) $journal->notes, $project, $issue->attachments()),
                ]),
        ])->render();

        return Pdf::loadHTML($html)
            ->download("{$project->identifier}-{$issue->id}.pdf");
    }
}
