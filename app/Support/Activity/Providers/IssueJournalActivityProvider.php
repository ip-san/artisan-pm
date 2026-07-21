<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Issue updates and comments (Journal rows) — a distinct activity type
 * from "issue" (the initial creation), matching Redmine's issue vs
 * issue-edit split.
 *
 * Journals with private_notes = true are excluded outright rather than
 * gated behind a permission: this app doesn't yet have a dedicated
 * "view private notes" permission anywhere (including the issue's own
 * show page, which is a pre-existing gap from Phase 1, not something
 * this feed should compound by surfacing private note content on a
 * higher-visibility, cross-issue page).
 */
final class IssueJournalActivityProvider implements ActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'issue-edit';
    }

    public function label(): string
    {
        return '課題の更新';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_issues', $project)) {
            return collect();
        }

        return Journal::query()
            ->whereHas('issue', fn ($query) => $query->where('project_id', $project->id))
            ->where('private_notes', false)
            ->whereBetween('created_at', [$from, $to])
            ->with(['issue.tracker', 'user', 'details'])
            ->get()
            ->reject(fn (Journal $journal) => $journal->isEmpty())
            ->map(fn (Journal $journal) => new ActivityEntry(
                type: $this->type(),
                title: "{$journal->issue->tracker->name} #{$journal->issue->id}: {$journal->issue->subject}",
                url: route('issues.show', [$project, $journal->issue]),
                authorName: $journal->user->name,
                occurredAt: $journal->created_at ?? throw new LogicException('Journal is missing created_at.'),
            ))
            ->values();
    }
}
