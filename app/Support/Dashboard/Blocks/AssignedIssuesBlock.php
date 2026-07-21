<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

final class AssignedIssuesBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'assigned_issues';
    }

    public function label(): string
    {
        return '自分の課題';
    }

    public function rows(User $user): Collection
    {
        return Issue::query()
            ->where('assigned_to_id', $user->id)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->with(['project', 'tracker'])
            ->orderBy('due_date')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Issue $issue) => new DashboardBlockRow(
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                meta: $issue->due_date?->toDateString(),
            ));
    }
}
