<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWatcherRequest;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Matches Redmine's WatchersController#create/#destroy: both are bare
 * action endpoints (no resource body, 204 No Content — Redmine's own
 * render_api_ok resolves to the same), not a CRUD resource. Both actions
 * gate on manageWatchers (add_issue_watchers) — this app's IssuePolicy,
 * unlike Redmine's dynamically-built add_issue_watchers/
 * delete_issue_watchers pair, only ever defined the one permission and
 * reuses it for both add and remove (the same convention the existing
 * web UI's addWatcher()/removeWatcher() already follow), so this
 * endpoint doesn't invent a new permission Redmine has but nothing else
 * here references.
 */
final class WatcherController extends Controller
{
    public function store(StoreWatcherRequest $request, Issue $issue): JsonResponse
    {
        $issue->watchers()->firstOrCreate(['user_id' => $request->validated('user_id')]);

        return response()->json(status: 204);
    }

    public function destroy(Issue $issue, User $user): JsonResponse
    {
        Gate::authorize('manageWatchers', $issue);

        $issue->watchers()->where('user_id', $user->id)->delete();

        return response()->json(status: 204);
    }
}
