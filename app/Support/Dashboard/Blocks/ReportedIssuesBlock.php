<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\User;
use App\Support\Format\DateTimes;
use Illuminate\Database\Eloquent\Builder;

final class ReportedIssuesBlock extends IssueListBlock
{
    public function key(): string
    {
        return 'reported_issues';
    }

    public function label(): string
    {
        return __('自分が登録した課題');
    }

    protected function issues(User $user): Builder
    {
        return Issue::query()
            ->visible($user)
            ->where('author_id', $user->id);
    }

    protected function defaultOrder(Builder $query): Builder
    {
        return $query->latest();
    }

    protected function defaultMeta(Issue $issue, User $user): ?string
    {
        return DateTimes::dateOf($issue->created_at, $user);
    }
}
