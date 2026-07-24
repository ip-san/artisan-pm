<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TimeEntryVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTimeEntryRequest;
use App\Http\Requests\Api\V1\UpdateTimeEntryRequest;
use App\Http\Resources\Api\V1\TimeEntryResource;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Redmine also nests time entry creation under /issues/:issue_id/time_entries
 * and exposes a top-level unscoped GET /time_entries — this app has no
 * precedent for either (no other resource exposes an issue-nested route,
 * and every other index here is project-scoped), so issue_id stays a
 * plain body field on the project-scoped routes below instead.
 */
final class TimeEntryController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [TimeEntry::class, $project]);

        $query = TimeEntry::query()->where('project_id', $project->id);

        if (app(AuthorizationService::class)->timeEntryVisibilityFor(auth()->user(), $project) === TimeEntryVisibility::Own) {
            $query->where('user_id', auth()->id());
        }

        $timeEntries = $query->orderBy('spent_on', 'desc')->orderBy('id', 'desc')->paginate();

        return TimeEntryResource::collection($timeEntries);
    }

    public function show(TimeEntry $timeEntry): TimeEntryResource
    {
        Gate::authorize('view', $timeEntry);

        return new TimeEntryResource($timeEntry);
    }

    public function store(StoreTimeEntryRequest $request, Project $project): JsonResponse
    {
        $timeEntry = app(TimeEntryService::class)->create([...$request->validated(), 'project_id' => $project->id]);

        return (new TimeEntryResource($timeEntry))->response()->setStatusCode(201);
    }

    public function update(UpdateTimeEntryRequest $request, TimeEntry $timeEntry): TimeEntryResource
    {
        $timeEntry = app(TimeEntryService::class)->update($timeEntry, $request->validated());

        return new TimeEntryResource($timeEntry);
    }

    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        Gate::authorize('delete', $timeEntry);

        app(TimeEntryService::class)->delete($timeEntry);

        return response()->json(status: 204);
    }
}
