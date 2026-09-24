<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use App\Support\Format\DateTimes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Redmine's "issues updated by me": the issues the user last touched with a
 * comment or a change, newest first, limited to what they may see.
 */
final class UpdatedByMeBlock extends IssueListBlock
{
    public function key(): string
    {
        return 'updated_by_me';
    }

    public function label(): string
    {
        return __('自分が更新した課題');
    }

    protected function issues(User $user): Builder
    {
        $projects = Project::query()->get()->filter(fn (Project $project) => $user->can('viewAny', [Issue::class, $project]))->values();
        $latest = Journal::query()
            ->where('user_id', $user->id)
            ->selectRaw('issue_id, MAX(created_at) as last_update')
            ->groupBy('issue_id');

        return Issue::query()
            ->visibleToAcrossProjects($user, $projects)
            ->joinSub($latest, 'mine', 'mine.issue_id', '=', 'issues.id')
            ->select('issues.*');
    }

    protected function defaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('mine.last_update');
    }

    protected function defaultMeta(Issue $issue, User $user): ?string
    {
        return DateTimes::dateOf($issue->updated_at, $user);
    }
}
