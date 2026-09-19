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
 * so an admin's configured window applies consistently to both. The entry
 * cap comes from Setting::feeds_limit (Redmine's setting of the same
 * name), which the news, board and issue feeds share via limit().
 */
final class ActivityFeedController extends Controller
{
    /**
     * Redmine's config/settings.yml default for feeds_limit.
     */
    public const DEFAULT_LIMIT = 15;

    /**
     * The maximum number of entries in any Atom feed: Redmine's
     * Setting.feeds_limit, shared by every feed endpoint so an admin's
     * value applies consistently. Never below one, so a corrupt stored
     * value can't produce an empty feed by accident.
     */
    public static function limit(): int
    {
        return max(1, (int) Setting::get('feeds_limit', self::DEFAULT_LIMIT));
    }

    public function __invoke(Project $project): Response
    {
        Gate::authorize('view', $project);

        $from = now()->subDays(Setting::get('activity_days_default', 7))->startOfDay();
        $to = now()->endOfDay();

        $entries = app(ActivityProviderRegistry::class)->all()
            ->flatMap(fn ($provider) => $provider->entries($project, auth()->user(), $from, $to))
            ->sortByDesc('occurredAt')
            ->take(self::limit())
            ->values();

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => "{$project->name} - ".config('app.name'),
            'alternateUrl' => route('activity.index', $project),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
