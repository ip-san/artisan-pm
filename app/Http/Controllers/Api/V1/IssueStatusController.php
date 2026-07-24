<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IssueStatusResource;
use App\Models\IssueStatus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only, matching Redmine's own IssueStatusesController: statuses are
 * site-wide admin-managed config, but the *index* action is reachable by
 * any authenticated API request (require_admin_or_api_request), unlike
 * the admin-only web UI (IssueStatusPolicy, gated by Gate::before's admin
 * bypass) — so this deliberately does NOT call Gate::authorize against
 * that policy, same as TrackerController. Authentication alone (the
 * route's auth:api,api-key middleware) is the only gate here. Redmine
 * itself has no API `show` action for issue statuses (only index), and
 * no write API at all (create/update/destroy are web-admin-only) — the
 * `show` action here follows the same beyond-Redmine-parity precedent
 * TrackerController already established.
 */
final class IssueStatusController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $statuses = IssueStatus::query()->orderBy('position')->get();

        return IssueStatusResource::collection($statuses);
    }

    public function show(IssueStatus $issueStatus): IssueStatusResource
    {
        return new IssueStatusResource($issueStatus);
    }
}
