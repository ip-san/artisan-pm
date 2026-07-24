<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TrackerResource;
use App\Models\Tracker;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only, matching Redmine's own TrackersController: trackers are
 * site-wide admin-managed config, but the *index* action is reachable by
 * any authenticated API request (require_admin_or_api_request), unlike
 * the admin-only web UI (TrackerPolicy, gated by Gate::before's admin
 * bypass) — so this deliberately does NOT call Gate::authorize against
 * that policy. Authentication alone (the route's auth:api,api-key
 * middleware) is the only gate here, matching Redmine exactly. Returns
 * the full list, unpaginated and unfiltered by project — Redmine's own
 * index has no project_id filter either.
 */
final class TrackerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $trackers = Tracker::query()->orderBy('position')->get();

        return TrackerResource::collection($trackers);
    }

    public function show(Tracker $tracker): TrackerResource
    {
        return new TrackerResource($tracker);
    }
}
