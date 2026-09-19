<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TimeEntryVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTimeEntryRequest;
use App\Http\Requests\Api\V1\UpdateTimeEntryRequest;
use App\Http\Resources\Api\V1\TimeEntryResource;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Api\CustomFieldPayload;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Like Redmine, time entries can be listed and created three ways: under a
 * project, under an issue (/issues/{issue}/time_entries — the issue is then
 * implied) and, for listing, across every project the caller may see
 * (/time_entries). issue_id remains a body field on the project routes.
 */
final class TimeEntryController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [TimeEntry::class, $project]);

        return $this->listTimeEntries($request, $this->projectQuery($project));
    }

    /**
     * /issues/{issue}/time_entries — the issue must itself be visible, and
     * the caller's time entry visibility tier for its project applies.
     */
    public function issueIndex(Request $request, Issue $issue): AnonymousResourceCollection
    {
        Gate::authorize('view', $issue);
        Gate::authorize('viewAny', [TimeEntry::class, $issue->project]);

        return $this->listTimeEntries($request, $this->projectQuery($issue->project)->where('issue_id', $issue->id));
    }

    /**
     * /time_entries — every project where the caller may view time entries,
     * each with its own visibility tier (the scope the global list uses).
     */
    public function globalIndex(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $projects = Project::query()->get()
            ->filter(fn (Project $project) => $user->can('viewAny', [TimeEntry::class, $project]))
            ->values();

        return $this->listTimeEntries($request, TimeEntry::query()->visibleToAcrossProjects($user, $projects));
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function projectQuery(Project $project): Builder
    {
        $query = TimeEntry::query()->where('project_id', $project->id);

        if (app(AuthorizationService::class)->timeEntryVisibilityFor(auth()->user(), $project) === TimeEntryVisibility::Own) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Optional filters, ANDed, malformed values ignored: project_id,
     * issue_id, activity_id, user_id (`me` is the caller) and a spent_on
     * range through from/to (YYYY-MM-DD, Redmine's own parameter names).
     *
     * @param  Builder<TimeEntry>  $query
     */
    private function listTimeEntries(Request $request, Builder $query): AnonymousResourceCollection
    {
        foreach (['project_id', 'issue_id', 'activity_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && ctype_digit($value)) {
                $query->where($column, (int) $value);
            }
        }

        $user = $request->query('user_id');

        if ($user === 'me') {
            $query->where('user_id', $request->user()->id);
        } elseif (is_string($user) && ctype_digit($user)) {
            $query->where('user_id', (int) $user);
        }

        foreach (['from' => '>=', 'to' => '<='] as $parameter => $operator) {
            $date = $request->query($parameter);

            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $query->where('spent_on', $operator, $date);
            }
        }

        $timeEntries = $query->orderBy('spent_on', 'desc')->orderBy('id', 'desc')->paginate();

        return TimeEntryResource::collection($timeEntries);
    }

    public function storeForIssue(StoreTimeEntryRequest $request, Issue $issue): JsonResponse
    {
        $customFieldData = CustomFieldPayload::extract($request, (new TimeEntry)->forceFill(['project_id' => $issue->project_id])->relevantCustomFields(), $request->user(), requireAll: true);

        $timeEntry = app(TimeEntryService::class)->create([
            ...$request->validated(),
            'project_id' => $issue->project_id,
            'issue_id' => $issue->id,
        ]);
        $timeEntry->setCustomFieldValues($customFieldData);

        return (new TimeEntryResource($timeEntry))->response()->setStatusCode(201);
    }

    public function show(TimeEntry $timeEntry): TimeEntryResource
    {
        Gate::authorize('view', $timeEntry);

        return new TimeEntryResource($timeEntry);
    }

    public function store(StoreTimeEntryRequest $request, Project $project): JsonResponse
    {
        $customFieldData = CustomFieldPayload::extract($request, (new TimeEntry)->forceFill(['project_id' => $project->id])->relevantCustomFields(), $request->user(), requireAll: true);

        $timeEntry = app(TimeEntryService::class)->create([...$request->validated(), 'project_id' => $project->id]);
        $timeEntry->setCustomFieldValues($customFieldData);

        return (new TimeEntryResource($timeEntry))->response()->setStatusCode(201);
    }

    public function update(UpdateTimeEntryRequest $request, TimeEntry $timeEntry): TimeEntryResource
    {
        $customFieldData = CustomFieldPayload::extract($request, $timeEntry->relevantCustomFields(), $request->user());

        $timeEntry = app(TimeEntryService::class)->update($timeEntry, $request->validated());
        $timeEntry->setCustomFieldValues($customFieldData);

        return new TimeEntryResource($timeEntry);
    }

    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        Gate::authorize('delete', $timeEntry);

        app(TimeEntryService::class)->delete($timeEntry);

        return response()->json(status: 204);
    }
}
