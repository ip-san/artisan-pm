<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

final class TimeEntriesBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'time_entries';
    }

    public function label(): string
    {
        return '最近の工数';
    }

    public function rows(User $user): Collection
    {
        return TimeEntry::query()
            ->where('user_id', $user->id)
            ->with(['project', 'activity'])
            ->latest('spent_on')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (TimeEntry $entry) => new DashboardBlockRow(
                title: "{$entry->project->name} — {$entry->activity->name} ({$entry->hours}h)",
                url: route('time-entries.index', $entry->project),
                meta: $entry->spent_on->toDateString(),
            ));
    }
}
