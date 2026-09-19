<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

/**
 * Redmine's "issues updated by me": the issues the user last touched with a
 * comment or a change, newest first, limited to what they may see.
 */
final class UpdatedByMeBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'updated_by_me';
    }

    public function label(): string
    {
        return '自分が更新した課題';
    }

    public function rows(User $user): Collection
    {
        $projects = Project::query()->get()->filter(fn (Project $project) => $user->can('viewAny', [Issue::class, $project]))->values();

        $latest = Journal::query()
            ->where('user_id', $user->id)
            ->selectRaw('issue_id, MAX(created_at) as last_update')
            ->groupBy('issue_id');

        return Issue::query()
            ->visibleToAcrossProjects($user, $projects)
            ->joinSub($latest, 'mine', 'mine.issue_id', '=', 'issues.id')
            ->select('issues.*')
            ->with(['project', 'tracker'])
            ->orderByDesc('mine.last_update')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Issue $issue) => new DashboardBlockRow(
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                meta: $issue->updated_at?->toDateString(),
            ));
    }
}
