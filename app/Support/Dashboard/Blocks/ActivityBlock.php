<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProviderRegistry;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

/**
 * Reuses the same ActivityProvider registry the per-project activity feed
 * (activity.index) is built from, fanning it out across every project the
 * user belongs to rather than one — each provider is still responsible
 * for its own permission check per project, same contract as the
 * single-project feed.
 */
final class ActivityBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    private const int LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly ActivityProviderRegistry $providers,
    ) {}

    public function key(): string
    {
        return 'activity';
    }

    public function label(): string
    {
        return '最近のアクティビティ';
    }

    public function rows(User $user): Collection
    {
        $projects = $user->projects()->get();
        $from = now()->subDays(self::LOOKBACK_DAYS)->startOfDay();
        $to = now()->endOfDay();

        return $projects
            ->flatMap(fn (Project $project) => $this->providers->all()
                ->flatMap(fn ($provider) => $provider->entries($project, $user, $from, $to)))
            ->sortByDesc(fn (ActivityEntry $entry) => $entry->occurredAt)
            ->take(self::MAX_ROWS)
            ->map(fn (ActivityEntry $entry) => new DashboardBlockRow(
                title: $entry->title,
                url: $entry->url,
                meta: $entry->occurredAt->toDateString(),
            ))
            ->values();
    }
}
