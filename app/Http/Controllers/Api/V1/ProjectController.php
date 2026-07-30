<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProjectController extends Controller
{
    /**
     * Mirrors the projects.index Livewire component's own visibility
     * filtering — there's no blanket "view any project" permission, only a
     * per-project one, so every candidate is checked individually.
     */
    public function index(): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => Gate::allows('view', $project))
            ->values();

        return ProjectResource::collection($projects);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $data = $request->validated();
        $trackerIds = $data['tracker_ids'];
        $modules = $data['modules'] ?? Setting::get(
            'default_projects_modules',
            array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults())
        );
        unset($data['tracker_ids'], $data['modules']);

        // Matches projects/form.blade.php's mount(): is_public isn't
        // required, so an omitted field should fall back to the
        // default_projects_public setting rather than silently leaving the
        // column's raw default (and the in-memory model's null, per the
        // same unrefreshed-attribute gap already worked around on status).
        $data['is_public'] ??= Setting::get('default_projects_public', true);

        $project = Project::create($data);

        // Matches ProjectsController#create in Redmine and this app's own
        // web form: an admin already sees every project regardless of
        // membership, so auto-adding one as a member would be a
        // meaningless no-op at best.
        if (! $request->user()->is_admin) {
            $project->addDefaultMember($request->user());
        }

        $project->syncModules(array_map(fn (string $m) => ProjectModuleKey::from($m), $modules));
        $project->trackers()->sync($trackerIds);

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $data = $request->validated();
        $trackerIds = $data['tracker_ids'] ?? null;
        $modules = $data['modules'] ?? null;
        unset($data['tracker_ids'], $data['modules']);

        if ($trackerIds !== null) {
            $this->guardTrackersInUse($project, $trackerIds);
        }

        $project->update($data);

        if ($modules !== null) {
            $project->syncModules(array_map(fn (string $m) => ProjectModuleKey::from($m), $modules));
        }

        if ($trackerIds !== null) {
            $project->trackers()->sync($trackerIds);
        }

        return new ProjectResource($project);
    }

    /**
     * Matches projects/form.blade.php's save(): a tracker can't be
     * detached from a project while issues on that project still use it.
     *
     * @param  array<int, int>  $trackerIds
     */
    private function guardTrackersInUse(Project $project, array $trackerIds): void
    {
        $removedTrackerIds = $project->trackers->pluck('id')->diff($trackerIds);

        if ($removedTrackerIds->isEmpty()) {
            return;
        }

        $blockedTrackerNames = Tracker::query()
            ->whereIn('id', $removedTrackerIds)
            ->whereHas('issues', fn ($query) => $query->where('project_id', $project->id))
            ->pluck('name');

        if ($blockedTrackerNames->isNotEmpty()) {
            throw ValidationException::withMessages([
                'tracker_ids' => 'このプロジェクトの課題で使用中のため外せません: '.$blockedTrackerNames->join(', '),
            ]);
        }
    }

    public function close(Project $project): JsonResponse
    {
        Gate::authorize('close', $project);

        $this->setStatus($project, ProjectStatus::Closed);

        return response()->json(status: 204);
    }

    public function reopen(Project $project): JsonResponse
    {
        Gate::authorize('close', $project);

        $this->setStatus($project, ProjectStatus::Active);

        return response()->json(status: 204);
    }

    public function archive(Project $project): JsonResponse
    {
        Gate::authorize('archive', $project);

        $this->setStatus($project, ProjectStatus::Archived);

        return response()->json(status: 204);
    }

    public function unarchive(Project $project): JsonResponse
    {
        Gate::authorize('archive', $project);

        $this->setStatus($project, ProjectStatus::Active);

        return response()->json(status: 204);
    }

    /**
     * Matches Redmine's ProjectsController#destroy for API requests: the
     * web UI's typed-identifier confirmation (params[:confirm] ==
     * identifier) is specific to the HTML form and is unconditionally
     * skipped for API requests there (`if api_request? || params[:confirm]
     * == ...`) — an API caller's credentials are themselves the
     * confirmation. Sudo mode (recent password reconfirmation) is
     * likewise web-session-only in Redmine (Redmine::SudoMode::
     * SudoRequestFilter#before returns true unconditionally for
     * api_request?) and has no API equivalent here either. Gate::authorize
     * still enforces ProjectPolicy::delete()'s admin-or-leaf-project rule.
     */
    public function destroy(Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return response()->json(status: 204);
    }

    /**
     * status isn't in Project's #[Fillable] list (matches
     * projects/show.blade.php's own setStatus(), which assigns the
     * property directly rather than going through update() for the same
     * reason) — a mass-assignment update(['status' => ...]) would
     * silently no-op instead of persisting the transition.
     */
    private function setStatus(Project $project, ProjectStatus $status): void
    {
        $project->status = $status;
        $project->save();
    }
}
