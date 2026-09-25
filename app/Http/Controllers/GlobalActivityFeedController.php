<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity\ActivityProviderRegistry;
use App\Support\Activity\CrossProjectEntries;
use App\Support\Activity\OffByDefault;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

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

        // Redmine's User.visible.active.find(params[:user_id]) — an
        // unknown or inactive id 404s.
        $author = request()->filled('userId')
            ? User::query()->where('status', UserStatus::Active)->findOrFail(request()->integer('userId'))
            : null;

        $from = now()->subDays(Setting::get('activity_days_default', 10))->startOfDay();
        $to = now()->endOfDay();

        $providers = app(ActivityProviderRegistry::class)->all();

        if ($author !== null) {
            // Redmine's @activity.scope = :all when an author is given:
            // every registered type, including off-by-default ones.
        } else {
            $remembered = array_values(array_intersect((array) $user?->preference('activity_scope'), $providers->map->type()->all()));
            $providers = $remembered !== []
                ? $providers->filter(fn ($provider) => in_array($provider->type(), $remembered, true))
                : $providers->reject(fn ($provider) => $provider instanceof OffByDefault);
        }

        $projects = Project::query()->get()->filter(fn (Project $project) => Gate::forUser($user)->allows('view', $project))->values();

        $entries = CrossProjectEntries::collect($providers, $projects, $user, $from, $to)
            ->when($author !== null, fn ($entries) => $entries->filter(fn ($entry) => $entry->authorId === $author->id))
            ->take(ActivityFeedController::limit())
            ->values();

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => $author !== null ? $author->displayName() : config('app.name').' - '.__('活動'),
            'alternateUrl' => route('activity.global-index'),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
