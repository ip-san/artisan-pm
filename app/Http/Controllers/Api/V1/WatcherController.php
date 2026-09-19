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
 * render_api_ok resolves to the same), not a CRUD resource. Add gates on
 * add_issue_watchers (IssuePolicy::addWatchers) and remove on
 * delete_issue_watchers (deleteWatchers).
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
        Gate::authorize('deleteWatchers', $issue);

        $issue->watchers()->where('user_id', $user->id)->delete();

        return response()->json(status: 204);
    }
}
