<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\News;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

final class LatestNewsBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'latest_news';
    }

    public function label(): string
    {
        return '最新のお知らせ';
    }

    /**
     * Scoped to projects the user is a member of, rather than
     * per-project view_news permission checks (which would mean one
     * query per project the user belongs to) — a reasonable proxy for a
     * dashboard summary block, not a substitute for the project's own
     * news page, which still enforces the real permission.
     */
    public function rows(User $user): Collection
    {
        $projectIds = $user->projects()->pluck('projects.id');

        return News::query()
            ->whereIn('project_id', $projectIds)
            ->with('project')
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (News $news) => new DashboardBlockRow(
                title: "{$news->project->name}: {$news->title}",
                url: route('news.show', [$news->project, $news]),
                meta: $news->created_at->toDateString(),
            ));
    }
}
