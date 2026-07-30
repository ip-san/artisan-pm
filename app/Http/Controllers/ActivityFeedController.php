<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Support\Activity\ActivityProviderRegistry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Renders the same aggregated activity this project's activity page shows
 * (see resources/views/livewire/activity/index.blade.php) as an Atom feed —
 * matches Redmine's project activity.atom, and now shares the same
 * Setting::activity_days_default (added 2026-07-30) the HTML view reads,
 * so an admin's configured window applies consistently to both. LIMIT
 * remains a hardcoded stand-in for Redmine's Setting.feeds_limit, which
 * this app doesn't expose as a setting yet.
 */
final class ActivityFeedController extends Controller
{
    /**
     * Public so other feed endpoints (BoardAtomController) share the
     * same cap — the stand-in for Redmine's Setting.feeds_limit.
     */
    public const LIMIT = 15;

    public function __invoke(Project $project): Response
    {
        Gate::authorize('view', $project);

        $from = now()->subDays(Setting::get('activity_days_default', 7))->startOfDay();
        $to = now()->endOfDay();

        $entries = app(ActivityProviderRegistry::class)->all()
            ->flatMap(fn ($provider) => $provider->entries($project, auth()->user(), $from, $to))
            ->sortByDesc('occurredAt')
            ->take(self::LIMIT)
            ->values();

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => "{$project->name} - ".config('app.name'),
            'alternateUrl' => route('activity.index', $project),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
