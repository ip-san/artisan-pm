<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\User;
use App\Support\Format\DateTimes;
use Illuminate\Database\Eloquent\Builder;

final class WatchedIssuesBlock extends IssueListBlock
{
    public function key(): string
    {
        return 'watched_issues';
    }

    public function label(): string
    {
        return __('ウォッチ中の課題');
    }

    protected function issues(User $user): Builder
    {
        return Issue::query()
            ->visible($user)
            ->whereHas('watchers', fn ($query) => $query->where('user_id', $user->id));
    }

    protected function defaultOrder(Builder $query): Builder
    {
        return $query->latest();
    }

    protected function defaultMeta(Issue $issue, User $user): ?string
    {
        return DateTimes::date($issue->due_date);
    }
}
