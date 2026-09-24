<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\User;
use App\Support\Format\DateTimes;
use Illuminate\Database\Eloquent\Builder;

final class AssignedIssuesBlock extends IssueListBlock
{
    public function key(): string
    {
        return 'assigned_issues';
    }

    public function label(): string
    {
        return __('自分の課題');
    }

    protected function issues(User $user): Builder
    {
        return Issue::query()
            ->visible($user)
            // Redmine's "assigned to me" includes the user's groups.
            ->assignedToUserOrGroups($user)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false));
    }

    protected function defaultOrder(Builder $query): Builder
    {
        return $query->orderBy('due_date');
    }

    protected function defaultMeta(Issue $issue, User $user): ?string
    {
        return DateTimes::date($issue->due_date);
    }
}
