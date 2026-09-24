<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use App\Support\Format\DateTimes;
use Illuminate\Support\Collection;

final class ReportedIssuesBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'reported_issues';
    }

    public function label(): string
    {
        return __('自分が登録した課題');
    }

    public function rows(User $user): Collection
    {
        return Issue::query()
            ->visible($user)
            ->where('author_id', $user->id)
            ->with(['project', 'tracker'])
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Issue $issue) => new DashboardBlockRow(
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                meta: DateTimes::dateOf($issue->created_at, $user),
            ));
    }
}
