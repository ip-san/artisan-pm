<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnumerationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EnumerationResource;
use App\Models\Enumeration;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only, matching Redmine's own EnumerationsController#index: site-wide
 * admin-managed config, reachable by any authenticated API request
 * (require_admin_or_api_request) — bypasses the admin-only EnumerationPolicy
 * for this action, same as RoleController/TrackerController/
 * IssueStatusController. Redmine collapses all three enumeration kinds
 * into one controller keyed by a dynamic `:type` route segment
 * (`GET /enumerations/:type`, resolved via Enumeration.get_subclass); this
 * app has no dynamic-segment precedent among its other read-only
 * resources, so each kind gets its own fixed route/action instead —
 * matching the three fixed URLs Redmine itself exposes
 * (issue_priorities/time_entry_activities/document_categories) — rather
 * than a generic `{type}` wildcard that would accept arbitrary strings.
 * Only global rows (project_id null) are returned, matching Redmine's
 * own `shared` scope — a project-specific TimeEntryActivity override
 * isn't a top-level enumeration in its own right. No show/per-id action
 * exists here either, matching Redmine's own index-only enumeration API.
 */
final class EnumerationController extends Controller
{
    public function issuePriorities(): AnonymousResourceCollection
    {
        return $this->index(EnumerationType::IssuePriority);
    }

    public function timeEntryActivities(): AnonymousResourceCollection
    {
        return $this->index(EnumerationType::TimeEntryActivity);
    }

    public function documentCategories(): AnonymousResourceCollection
    {
        return $this->index(EnumerationType::DocumentCategory);
    }

    private function index(EnumerationType $type): AnonymousResourceCollection
    {
        $enumerations = Enumeration::ofType($type)->whereNull('project_id')->orderBy('position')->get();

        return EnumerationResource::collection($enumerations);
    }
}
