<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CustomizableType;
use App\Enums\ProjectModuleKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Support\Api\CustomFieldPayload;
use App\Support\Api\RedmineIssueListParams;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\ProjectFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProjectController extends Controller
{
    /**
     * Redmine's GET /projects.json: the projects the caller may see (the
     * same set as the projects.index list), narrowed by the ProjectQuery
     * filters in Redmine's f[]/op[]/v[] or short `field=[operator]value`
     * form (RedmineIssueListParams, as GET /issues.json reads them), with
     * limit/offset (or page) and total_count/offset/limit beside the data.
     * Still ordered by name, as this endpoint always has been.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $input = $request->query();
        $visibleProjectIds = app(AuthorizationService::class)->visibleProjectIds($user, 'view_project');
        $engine = new QueryFilterEngine(ProjectFilterFieldRegistry::forViewer($user, $visibleProjectIds));

        $query = $engine->applyFilters(
            Project::query()->whereIn('id', $visibleProjectIds)->with(['defaultVersion', 'defaultAssignedTo', 'defaultAssignedToGroup', 'defaultIssueQuery']),
            RedmineIssueListParams::filters($input, $engine, $user),
        )->orderBy('name')->orderBy('id');

        [$offset, $limit] = RedmineIssueListParams::offsetAndLimit($input);
        $total = (clone $query)->toBase()->getCountForPagination();

        $projects = new LengthAwarePaginator(
            $query->skip($offset)->take($limit)->get(),
            $total,
            $limit,
            intdiv($offset, $limit) + 1,
            ['path' => $request->url(), 'query' => Arr::except($input, ['page', 'offset'])],
        );

        return ProjectResource::collection($projects)->additional([
            'total_count' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
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
        $issueCustomFieldIds = $data['issue_custom_field_ids'] ?? [];
        $modules = $data['modules'] ?? Setting::get(
            'default_projects_modules',
            array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults())
        );
        unset($data['tracker_ids'], $data['modules'], $data['issue_custom_field_ids']);

        // Matches projects/form.blade.php's mount(): is_public isn't
        // required, so an omitted field should fall back to the
        // default_projects_public setting rather than silently leaving the
        // column's raw default (and the in-memory model's null, per the
        // same unrefreshed-attribute gap already worked around on status).
        // Without select_project_publicity the value is ignored, as in the web form.
        if (! Project::mayChoosePublicity($request->user(), null)) {
            unset($data['is_public']);
        }

        $data['is_public'] ??= Setting::get('default_projects_public', true);

        // As in the web form: ignored unless the requester can see the parent.
        if (! Project::mayChooseInheritMembers($request->user(), $data['parent_id'] ?? null)) {
            unset($data['inherit_members']);
        }

        // A project that does not exist yet has no roles to hide fields from.
        $customFieldData = CustomFieldPayload::extract(
            $request,
            CustomField::query()->where('customized_type', CustomizableType::Project)->orderBy('position')->get(),
            $request->user(),
            requireAll: true,
        );

        $project = Project::create($data);
        $project->setCustomFieldValues($customFieldData);

        // Matches ProjectsController#create in Redmine and this app's own
        // web form: an admin already sees every project regardless of
        // membership, so auto-adding one as a member would be a
        // meaningless no-op at best.
        if (! $request->user()->is_admin) {
            $project->addDefaultMember($request->user());
        }

        $project->syncModules(array_map(fn (string $m) => ProjectModuleKey::from($m), $modules));
        $project->trackers()->sync($trackerIds);
        $project->syncIssueCustomFieldIds($issueCustomFieldIds);

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $data = $request->validated();
        $trackerIds = $data['tracker_ids'] ?? null;
        $modules = $data['modules'] ?? null;
        $issueCustomFieldIds = $data['issue_custom_field_ids'] ?? null;
        unset($data['tracker_ids'], $data['modules'], $data['issue_custom_field_ids']);

        // `default_assigned_to_id: null` clears a group default too.
        if (array_key_exists('default_assigned_to_id', $data) && $data['default_assigned_to_id'] === null && ! array_key_exists('default_assigned_to_group_id', $data)) {
            $data['default_assigned_to_group_id'] = null;
        }

        if ($trackerIds !== null) {
            $this->guardTrackersInUse($project, $trackerIds);
        }

        if ($issueCustomFieldIds !== null) {
            $this->guardIssueCustomFieldLastLink($project, $issueCustomFieldIds);
        }

        if (! Project::mayChoosePublicity($request->user(), $project)) {
            unset($data['is_public']);
        }

        if (! Project::mayChooseInheritMembers($request->user(), array_key_exists('parent_id', $data) ? $data['parent_id'] : $project->parent_id)) {
            unset($data['inherit_members']);
        }

        $customFieldData = CustomFieldPayload::extract($request, $project->relevantCustomFields(), $request->user());

        $project->update($data);
        $project->setCustomFieldValues($customFieldData);

        if ($modules !== null) {
            $project->syncModules(array_map(fn (string $m) => ProjectModuleKey::from($m), $modules));
        }

        if ($trackerIds !== null) {
            $project->trackers()->sync($trackerIds);
        }

        if ($issueCustomFieldIds !== null) {
            $project->syncIssueCustomFieldIds($issueCustomFieldIds);
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
                'tracker_ids' => __('このプロジェクトの課題で使用中のため外せません: :trackers', ['trackers' => $blockedTrackerNames->join(', ')]),
            ]);
        }
    }

    /**
     * Matches projects/form.blade.php's save(): dropping a field that is
     * only explicitly linked to this project would flip its pivot
     * globally empty (CustomField::isForAll()), applying it to every
     * OTHER project too — see Project::issueCustomFieldsLosingTheirLastLink().
     *
     * @param  array<int, int>  $issueCustomFieldIds
     */
    private function guardIssueCustomFieldLastLink(Project $project, array $issueCustomFieldIds): void
    {
        $fieldsLosingTheirLastLink = $project->issueCustomFieldsLosingTheirLastLink($issueCustomFieldIds);

        if ($fieldsLosingTheirLastLink->isNotEmpty()) {
            throw ValidationException::withMessages([
                'issue_custom_field_ids' => __('この項目は他のどのプロジェクトにも紐付いていないため、ここで外すと全プロジェクト共通になってしまいます。外すにはカスタムフィールドの管理画面から対象プロジェクトを変更してください: :fields', ['fields' => $fieldsLosingTheirLastLink->pluck('name')->join(', ')]),
            ]);
        }
    }

    public function close(Project $project): JsonResponse
    {
        Gate::authorize('close', $project);

        $project->close();

        return response()->json(status: 204);
    }

    public function reopen(Project $project): JsonResponse
    {
        Gate::authorize('close', $project);

        $project->reopen();

        return response()->json(status: 204);
    }

    public function archive(Project $project): JsonResponse
    {
        Gate::authorize('archive', $project);

        if (! $project->archive()) {
            return response()->json(['errors' => [__('このプロジェクトはアーカイブできません')]], 422);
        }

        return response()->json(status: 204);
    }

    public function unarchive(Project $project): JsonResponse
    {
        Gate::authorize('archive', $project);

        if (! $project->isOpen()) {
            $project->unarchive();
        }

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
}
