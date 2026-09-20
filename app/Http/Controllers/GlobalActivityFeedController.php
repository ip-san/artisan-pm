<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use App\Support\Activity\ActivityProviderRegistry;
use App\Support\Activity\CrossProjectEntries;
use App\Support\Activity\OffByDefault;
use Illuminate\Http\Response;

/**
 * The activity of every project the viewer can see, as an Atom feed
 * (Redmine's /activity.atom). Covers the event types the viewer last chose on
 * the activity page (activity_scope), or every type that is not off by
 * default; capped at feeds_limit like the other feeds.
 */
final class GlobalActivityFeedController extends Controller
{
    public function __invoke(): Response
    {
        $user = auth()->user();
        $from = now()->subDays(Setting::get('activity_days_default', 7))->startOfDay();
        $to = now()->endOfDay();

        $providers = app(ActivityProviderRegistry::class)->all();
        $remembered = array_values(array_intersect((array) $user?->preference('activity_scope'), $providers->map->type()->all()));
        $providers = $remembered !== []
            ? $providers->filter(fn ($provider) => in_array($provider->type(), $remembered, true))
            : $providers->reject(fn ($provider) => $provider instanceof OffByDefault);

        $projects = Project::query()->get()->filter(fn (Project $project) => $user?->can('view', $project))->values();

        $entries = CrossProjectEntries::collect($providers, $projects, $user, $from, $to)
            ->take(ActivityFeedController::limit())
            ->values();

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => config('app.name').' - '.'活動',
            'alternateUrl' => route('activity.global-index'),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
