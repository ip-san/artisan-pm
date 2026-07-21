<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Activity\ActivityProviderRegistry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Renders the same aggregated activity this project's activity page shows
 * (see resources/views/livewire/activity/index.blade.php) as an Atom feed —
 * matches Redmine's project activity.atom. Defaults mirror Redmine's own
 * activity_days_default/feeds_limit settings (10 days, 15 entries) rather
 * than exposing them as configurable Settings, to keep this endpoint
 * simple; a query string could reasonably add that later.
 */
final class ActivityFeedController extends Controller
{
    private const DAYS = 10;

    private const LIMIT = 15;

    public function __invoke(Project $project): Response
    {
        Gate::authorize('view', $project);

        $from = now()->subDays(self::DAYS)->startOfDay();
        $to = now()->endOfDay();

        $entries = app(ActivityProviderRegistry::class)->all()
            ->flatMap(fn ($provider) => $provider->entries($project, auth()->user(), $from, $to))
            ->sortByDesc('occurredAt')
            ->take(self::LIMIT)
            ->values();

        $xml = view('feeds.activity-atom', [
            'project' => $project,
            'entries' => $entries,
            'title' => "{$project->name} - ".config('app.name'),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
