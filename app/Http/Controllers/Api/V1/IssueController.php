<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\IssueTimeEntryDisposition;
use App\Enums\QueryType;
use App\Enums\UserStatus;
use App\Exceptions\StaleIssueUpdateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIssueRequest;
use App\Http\Requests\Api\V1\UpdateIssueRequest;
use App\Http\Resources\Api\V1\IssueResource;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Tracker;
use App\Services\IssueService;
use App\Services\WorkflowService;
use App\Support\Api\CustomFieldPayload;
use App\Support\Api\RedmineIssueListParams;
use App\Support\Attachments\PendingUploadAttacher;
use App\Support\Authorization\AuthorizationService;
use App\Support\Issues\IssueFieldRules;
use App\Support\Issues\StartDateDefault;
use App\Support\Issues\SubprojectScope;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class IssueController extends Controller
{
    /**
     * What show's ?include= can request — Redmine's own keys.
     * allowed_statuses and changesets are not relations: the resource
     * computes them from the include list recorded in the request
     * attributes below.
     *
     * @var array<int, string>
     */
    private const array SHOW_INCLUDES = ['journals', 'relations', 'attachments', 'children', 'watchers', 'allowed_statuses', 'changesets'];

    /**
     * Matches Redmine's own index action (issues/index.api.rsb), which
     * honors ?include=attachments and ?include=relations — every other key
     * is show-only there too.
     *
     * @var array<int, string>
     */
    private const array INDEX_INCLUDES = ['attachments', 'relations'];

    /**
     * Redmine's sort names of the issue columns stored on the issue itself
     * (IssueQuery#available_columns), by column.
     *
     * @var array<string, string>
     */
    private const array SORT_COLUMNS = [
        'id' => 'id',
        'subject' => 'subject',
        'created_on' => 'created_at',
        'updated_on' => 'updated_at',
        'closed_on' => 'closed_on',
        'start_date' => 'start_date',
        'due_date' => 'due_date',
        'done_ratio' => 'done_ratio',
        'estimated_hours' => 'estimated_hours',
        'is_private' => 'is_private',
    ];

    /**
     * Redmine's sort names of the columns sorted like the web list (the
     * filter engine's field), by engine key.
     *
     * @var array<string, string>
     */
    private const array SORT_FIELDS = [
        'project' => 'project_id',
        'tracker' => 'tracker_id',
        'status' => 'status_id',
        'priority' => 'priority_id',
        'author' => 'author_id',
        'assigned_to' => 'assigned_to_id',
        'category' => 'category_id',
        'fixed_version' => 'fixed_version_id',
        'parent' => 'parent_id',
    ];

    /**
     * The simple list parameters handled here before the Redmine filters
     * are read; a value in another shape (status_id=*, author_id=!5, ...)
     * is read as Redmine's short filter form instead.
     *
     * @var array<int, string>
     */
    private const array SIMPLE_FILTER_KEYS = ['status_id', 'project_id', 'tracker_id', 'priority_id', 'category_id', 'fixed_version_id', 'parent_id', 'author_id', 'assigned_to_id'];

    /**
     * Only what the caller's role lets them see: view_issues on the project
     * plus the per-issue visibility tier (private issues, "own issues
     * only"), exactly as the issue list and Atom feed apply it.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        // The projects covered depend on a subproject_id filter, as in
        // Redmine's project_statement (SubprojectScope).
        return $this->listIssues($request, function (array $filters) use ($project, $request): array {
            $projects = SubprojectScope::projectsForIssues($project, $request->user(), $filters);

            return [
                Issue::query()->visibleToAcrossProjects($request->user(), $projects),
                new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $request->user(), $projects)),
            ];
        });
    }

    /**
     * Redmine's project-less GET /issues.json: issues across every project
     * the caller may view issues in, each project's own visibility tier
     * applied (the same scope as the global issue list).
     */
    public function globalIndex(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $projects = Project::query()->get()
            ->filter(fn (Project $project) => $user->can('viewAny', [Issue::class, $project]))
            ->values();

        $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProjects($projects, $user));

        return $this->listIssues($request, fn (array $filters): array => [Issue::query()->visibleToAcrossProjects($user, $projects), $engine]);
    }

    /**
     * Shared by both indexes. The query is already limited to the issues
     * the caller may see; every filter below only narrows it.
     *
     * - The simple Redmine-style parameters, all ANDed; a malformed value
     *   is ignored rather than failing: status_id (open, closed, an id, or
     *   Redmine's short form such as `*` for every status — absent means
     *   open issues only, Redmine's default status filter, unless f[] or
     *   query_id gives the filters), project_id (global index only
     *   matters there), tracker_id, priority_id, category_id,
     *   fixed_version_id, parent_id, author_id and assigned_to_id (`me`
     *   means the caller), and sort=column[:desc] over id, subject,
     *   created_on and updated_on.
     * - Every filter of the web list, in Redmine's f[]/op[]/v[] form or its
     *   short `field=[operator]value` form (RedmineIssueListParams), or a
     *   saved issue query the caller may see (query_id).
     * - Redmine's limit (default 25, at most 100) and offset, or page;
     *   total_count/offset/limit are returned next to the usual meta.
     *
     * @param  Closure(array<string, mixed>): array{0: Builder<Issue>, 1: QueryFilterEngine}  $listFor  the issues the caller may see and the filter engine, for the given filters
     */
    private function listIssues(Request $request, Closure $listFor): AnonymousResourceCollection
    {
        $input = $request->query();
        [$query, $engine] = $listFor([]);
        $consumed = $this->applyIndexFilters($request, $query);
        $savedQuery = $this->savedQuery($request);

        $filters = $savedQuery !== null
            ? $savedQuery->filters
            : RedmineIssueListParams::filters($input, $engine, $request->user(), $consumed);

        // A subproject_id filter widens the projects covered; the filters
        // are then read again against that list's own fields.
        if (SubprojectScope::takesInSubprojects($filters) !== SubprojectScope::takesInSubprojects([])) {
            [$query, $engine] = $listFor($filters);
            $this->applyIndexFilters($request, $query);
            $filters = $savedQuery !== null
                ? $savedQuery->filters
                : RedmineIssueListParams::filters($input, $engine, $request->user(), $consumed);
        }

        $engine->applyFilters($query, $filters);
        $this->applySort($request, $query, $engine, $savedQuery);

        // One query each for the child count and logged hours, instead of
        // one per issue when the resource asks isLeaf()/spentHours().
        $query->withCount('children')->withSum('timeEntries', 'hours')->with(['assignedTo', 'assignedToGroup']);

        $includes = $this->parseIncludes($request, self::INDEX_INCLUDES);
        $query->with($this->relationsToLoad($includes));

        [$offset, $limit] = RedmineIssueListParams::offsetAndLimit($input);
        $total = (clone $query)->toBase()->getCountForPagination();

        $issues = new LengthAwarePaginator(
            $query->skip($offset)->take($limit)->get(),
            $total,
            $limit,
            intdiv($offset, $limit) + 1,
            ['path' => $request->url(), 'query' => Arr::except($input, ['page', 'offset'])],
        );

        return IssueResource::collection($issues)->additional([
            'total_count' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * The saved issue query named by query_id, which the caller must be
     * allowed to see (Redmine answers 403 otherwise). Its filters apply
     * within the list being asked for, as Redmine's retrieve_query does.
     */
    private function savedQuery(Request $request): ?SavedQuery
    {
        $id = $request->query('query_id');

        if ($id === null) {
            return null;
        }

        abort_unless(is_string($id) && ctype_digit($id), 404);

        $savedQuery = SavedQuery::query()->where('type', QueryType::Issue->value)->find((int) $id);

        abort_if($savedQuery === null, 404);
        abort_unless($savedQuery->visibleTo($request->user()), 403);

        return $savedQuery;
    }

    /**
     * Redmine's IssueQuery starts from its default "status: open" filter
     * unless the request replaces the filters (f[]) or names a saved query.
     */
    private function defaultsToOpenIssues(Request $request): bool
    {
        return $request->query('query_id') === null
            && ! is_array($request->query('f') ?? $request->query('fields'));
    }

    /**
     * @param  Builder<Issue>  $query
     * @return array<int, string> the simple parameters it applied
     */
    private function applyIndexFilters(Request $request, Builder $query): array
    {
        $consumed = [];
        $status = $request->query('status_id');

        if ($status === 'open' || $status === 'closed') {
            $query->whereHas('status', fn (Builder $q) => $q->where('is_closed', $status === 'closed'));
            $consumed[] = 'status_id';
        } elseif (is_string($status) && ctype_digit($status)) {
            $query->where('status_id', (int) $status);
            $consumed[] = 'status_id';
        } elseif ($status === null && $this->defaultsToOpenIssues($request)) {
            $query->whereHas('status', fn (Builder $q) => $q->where('is_closed', false));
        }

        foreach (['project_id', 'tracker_id', 'priority_id', 'category_id', 'fixed_version_id', 'parent_id', 'author_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && ctype_digit($value)) {
                $query->where($column, (int) $value);
                $consumed[] = $column;
            }
        }

        $assignee = $request->query('assigned_to_id');

        if ($assignee === 'me') {
            // Redmine's `me` includes the caller's groups.
            $query->assignedToUserOrGroups($request->user());
            $consumed[] = 'assigned_to_id';
        } elseif (is_string($assignee) && ctype_digit($assignee)) {
            $query->where('assigned_to_id', (int) $assignee);
            $consumed[] = 'assigned_to_id';
        }

        // A simple key given in no shape the code above reads is left to
        // the short filter form only when it is a string at all.
        return [...$consumed, ...array_values(array_diff(self::SIMPLE_FILTER_KEYS, array_keys($request->query())))];
    }

    /**
     * sort=column[:desc] over id, subject, created_on and updated_on; else
     * the saved query's own sort; else newest first.
     *
     * @param  Builder<Issue>  $query
     */
    private function applySort(Request $request, Builder $query, QueryFilterEngine $engine, ?SavedQuery $savedQuery): void
    {
        $sort = $request->query('sort');

        if (is_string($sort) && $this->applyRequestedSort($sort, $query, $engine)) {
            $query->orderByDesc('issues.id');

            return;
        }

        if ($savedQuery !== null && is_array($savedQuery->sort_criteria) && $savedQuery->sort_criteria !== []) {
            $engine->applySort($query, $savedQuery->sort_criteria);
        }

        $query->orderByDesc('id');
    }

    /**
     * Redmine's `sort` parameter (SortCriteria): up to three comma-separated
     * `column[:desc]` terms over the list's sortable columns — the issue's
     * own columns, its associations (sorted like the web list) and custom
     * fields the caller may see (`cf_N`). Unknown terms are skipped; false
     * when none applied.
     *
     * @param  Builder<Issue>  $query
     */
    private function applyRequestedSort(string $sort, Builder $query, QueryFilterEngine $engine): bool
    {
        $applied = false;

        foreach (array_slice(array_filter(array_map('trim', explode(',', $sort))), 0, 3) as $term) {
            [$name, $direction] = array_pad(explode(':', $term, 2), 2, 'asc');
            $direction = $direction === 'desc' ? 'desc' : 'asc';

            if (isset(self::SORT_COLUMNS[$name])) {
                $query->orderBy('issues.'.self::SORT_COLUMNS[$name], $direction);
                $applied = true;

                continue;
            }

            $key = self::SORT_FIELDS[$name] ?? (preg_match('/^cf_\d+$/', $name) === 1 ? $name : null);

            if ($key !== null && $engine->field($key)?->isSortable()) {
                $engine->applySort($query, [[$key, $direction]]);
                $applied = true;
            }
        }

        return $applied;
    }

    public function show(Request $request, Issue $issue): IssueResource
    {
        Gate::authorize('view', $issue);

        $includes = $this->parseIncludes($request, self::SHOW_INCLUDES);

        // Watchers are listed only to callers holding view_issue_watchers.
        if (! Gate::allows('viewWatchers', $issue)) {
            $includes = array_values(array_diff($includes, ['watchers']));
        }

        $request->attributes->set('issue_api_includes', $includes);

        $issue->load($this->relationsToLoad($includes));

        if (in_array('children', $includes, true)) {
            $this->loadDescendants($issue);
        }

        return new IssueResource($issue);
    }

    public function store(StoreIssueRequest $request, Project $project): JsonResponse
    {
        return $this->createIssue($request, $project);
    }

    /**
     * Redmine's POST /issues.json: the project comes from the body's
     * project_id (an id or an identifier).
     */
    public function storeWithProjectInBody(StoreIssueRequest $request): JsonResponse
    {
        return $this->createIssue($request, $request->targetProject());
    }

    /**
     * Mirrors the issue form's save(): the fields gated by a permission —
     * parent_issue_id (manage_subtasks), is_private (set_issues_private or
     * set_own_issues_private) and watcher_user_ids (add_issue_watchers) —
     * are dropped for a caller without it, as Redmine's safe_attributes
     * drop them. The status is one the workflow lets the caller start in,
     * else the tracker's default; a tracker that is private by default
     * makes the issue private unless is_private is given.
     */
    private function createIssue(StoreIssueRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $uploads = $data['uploads'] ?? [];
        $watcherIds = $data['watcher_user_ids'] ?? [];
        $parentId = $data['parent_issue_id'] ?? null;
        $requestedStatusId = isset($data['status_id']) ? (int) $data['status_id'] : null;
        unset($data['uploads'], $data['watcher_user_ids'], $data['parent_issue_id'], $data['status_id'], $data['project_id']);

        $tracker = Tracker::query()->findOrFail($data['tracker_id']);

        if ($user->can('manageSubtasks', [Issue::class, $project]) && $parentId !== null) {
            $data['parent_id'] = (int) $parentId;
        }

        if ($user->can('setPrivate', [Issue::class, $project])) {
            $data['is_private'] = array_key_exists('is_private', $data) ? (bool) $data['is_private'] : (bool) $tracker->private_by_default;
        } else {
            unset($data['is_private']);
        }

        $workflow = app(WorkflowService::class);
        $statusId = $requestedStatusId !== null && $workflow->initialStatuses($project, $tracker, $user)->contains('id', $requestedStatusId)
            ? $requestedStatusId
            : $workflow->defaultInitialStatusId($project, $tracker, $user);

        // A custom field the workflow makes read-only is not the caller's to
        // fill, so it is neither validated nor kept (Redmine validates only
        // editable_custom_field_values).
        $newIssue = (new Issue)->forceFill(['project_id' => $project->id, 'tracker_id' => $tracker->id, 'status_id' => $statusId, 'author_id' => $user->id]);
        $fieldRules = IssueFieldRules::for($newIssue, $user);

        $customFieldData = CustomFieldPayload::extract(
            $request,
            $newIssue->relevantCustomFields()->reject(fn (CustomField $field) => $fieldRules->isReadOnly("cf_{$field->id}")),
            $user,
            requireAll: true,
            project: $project,
        );

        $data['start_date'] = filled($data['start_date'] ?? null) ? $data['start_date'] : StartDateDefault::forApiAndMail($user);

        // The workflow's read-only fields and those the tracker disables are
        // ignored, its required ones must be given (422 otherwise).
        $issue = app(IssueService::class)->create(
            [...$data, 'project_id' => $project->id, 'status_id' => $statusId],
            $user,
            $customFieldData,
            applyFieldRules: true,
        );

        // Active members only, like the form's watcher picker.
        if ($watcherIds !== [] && app(AuthorizationService::class)->can($user, 'add_issue_watchers', $project)) {
            $project->users()
                ->where('users.status', UserStatus::Active->value)
                ->whereIn('users.id', array_map('intval', $watcherIds))
                ->pluck('users.id')
                ->each(fn (int $watcherId) => $issue->watchers()->firstOrCreate(['user_id' => $watcherId]));
        }

        // Not journaled — an issue's creation itself isn't journaled
        // either, matching the web form's own reasoning for uploads
        // attached while creating vs. editing an issue.
        $this->attachUploads($issue, $uploads);

        return (new IssueResource($issue->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Send the `lock_version` last read (it is part of every issue payload)
     * to make the update conditional: if someone saved in between, nothing
     * is changed and the response is 409 Conflict carrying the current
     * lock_version. Omitting it keeps the last-write-wins behaviour every
     * existing client relies on. (Redmine answers a stale API update with a
     * bare 422; 409 is the accurate status.)
     */
    public function update(UpdateIssueRequest $request, Issue $issue): IssueResource|JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $uploads = $data['uploads'] ?? [];
        $expectedLockVersion = isset($data['lock_version']) ? (int) $data['lock_version'] : null;
        $notes = filled($data['notes'] ?? null) ? $data['notes'] : null;
        $notesArePrivate = (bool) ($data['private_notes'] ?? false) && $user->can('setNotesPrivate', $issue);
        $canEdit = $user->can('update', $issue);
        $isPrivate = array_key_exists('is_private', $data) && $user->can('setPrivateOn', $issue) ? (bool) $data['is_private'] : null;
        // Redmine's project_id: moves the issue into another project the
        // caller may add issues to (validated by UpdateIssueRequest); the
        // other fields are then those of the target project.
        $movingTo = $canEdit ? $request->movingTo() : null;
        $project = $movingTo ?? $issue->project;
        unset($data['uploads'], $data['lock_version'], $data['notes'], $data['private_notes'], $data['is_private'], $data['project_id']);

        if ($movingTo !== null) {
            $data['project_id'] = $movingTo->id;
        }

        // Mirrors the issue form's save() and Redmine's safe_attributes: a
        // caller who may only add notes changes no field (is_private aside,
        // which has its own permission), and the parent is taken only from
        // those holding manage_subtasks.
        if (! $canEdit) {
            $data = [];
        } elseif (array_key_exists('parent_issue_id', $data)) {
            if ($user->can('manageSubtasks', [Issue::class, $project])) {
                $data['parent_id'] = $data['parent_issue_id'] !== null ? (int) $data['parent_issue_id'] : null;
            }

            unset($data['parent_issue_id']);
        }

        if ($isPrivate !== null) {
            $data['is_private'] = $isPrivate;
        }

        // `assigned_to_id: null` unassigns the issue, a group assignee too.
        if (array_key_exists('assigned_to_id', $data) && $data['assigned_to_id'] === null && ! array_key_exists('assigned_to_group_id', $data)) {
            $data['assigned_to_group_id'] = null;
        }

        if (isset($data['status_id']) && $data['status_id'] !== $issue->status_id) {
            Gate::authorize('transitionTo', [$issue, IssueStatus::findOrFail($data['status_id'])]);
        }

        try {
            // A custom field read-only under the resulting tracker and status
            // is ignored before its value is validated, as in Redmine.
            $targetRules = $canEdit ? IssueFieldRules::filterInput($issue, $data, [], $user)[2] : null;
            $targetState = (clone $issue)->forceFill(['project_id' => $project->id, 'tracker_id' => $data['tracker_id'] ?? $issue->tracker_id])->setRelation('project', $project);
            $customFieldData = $targetRules !== null
                ? CustomFieldPayload::extract($request, $targetState->relevantCustomFields()->reject(fn (CustomField $field) => $targetRules->isReadOnly("cf_{$field->id}")), $user, project: $project)
                : [];
            // Like the issue form: read-only and disabled fields are ignored and
            // the required ones must stay filled — for a caller who edits; a
            // notes-only update is a comment, as on the issue page.
            $issue = app(IssueService::class)->update($issue, $data, $user, $notes, $customFieldData, $expectedLockVersion, $notesArePrivate, applyFieldRules: $canEdit,
                attachFiles: fn (Issue $saved) => $this->attachUploads($saved, $uploads));

            // Redmine's deleted_attachment_ids: only for someone who may edit the issue.
            if ($canEdit && is_array($request->input('deleted_attachment_ids'))) {
                $ids = collect($request->input('deleted_attachment_ids'))->filter(fn (mixed $id) => is_int($id) || (is_string($id) && ctype_digit($id)))->map(fn (mixed $id) => (int) $id);
                // Only this issue's own attachments: an id from anywhere else matches nothing.
                $removed = $issue->attachments()->whereIn('id', $ids->all())->values();
                $removed->each->delete();
                app(IssueService::class)->journalizeAttachments($issue, $removed, added: false, actor: $user);
            }
        } catch (StaleIssueUpdateException $exception) {
            return response()->json([
                'message' => __('課題が他のユーザーによって更新されています。最新の内容を取得して、もう一度やり直してください。'),
                'errors' => ['lock_version' => ['The issue was modified after the given lock_version was read.']],
                'lock_version' => $exception->issue->lock_version,
            ], 409);
        }

        return new IssueResource($issue);
    }

    /**
     * Redmine's `todo` / `reassign_to_id` parameters choose what happens to
     * the issue's logged time. Omitting `todo` keeps the entries detached
     * from any issue (see IssueService::delete() for why that differs from
     * Redmine's delete-them default) — or deletes them when
     * `timelog_required_fields` names the issue, where `todo=nullify` is a
     * 422 as in Redmine (A1-40).
     */
    public function destroy(Request $request, Issue $issue): JsonResponse
    {
        Gate::authorize('delete', $issue);

        $data = $request->validate([
            'todo' => ['nullable', Rule::enum(IssueTimeEntryDisposition::class)],
            'reassign_to_id' => ['nullable', 'integer'],
        ]);

        app(IssueService::class)->delete(
            $issue,
            IssueTimeEntryDisposition::tryFrom($data['todo'] ?? ''),
            isset($data['reassign_to_id']) ? (int) $data['reassign_to_id'] : null,
        );

        return response()->json(status: 204);
    }

    /**
     * Redeems each {token, filename?, description?} entry against
     * PendingUploadToken, moving the underlying Media onto this issue —
     * matches Redmine's Issue#save_attachments. An unknown/already-claimed
     * token is silently skipped rather than failing the whole request,
     * same as Redmine's own tolerant handling there.
     *
     * @param  array<int, array{token?: string, filename?: string, description?: string}>  $uploads
     * @return array<int, Media> the attached files — on an update, update()
     *                           journals them with the edit (one mail)
     */
    private function attachUploads(Issue $issue, array $uploads): array
    {
        $attached = [];

        foreach ($uploads as $upload) {
            $media = PendingUploadAttacher::attach($upload, $issue, 'attachments');

            if ($media !== null) {
                $attached[] = $media;
            }
        }

        return $attached;
    }

    /**
     * Comma-split, trimmed, and restricted to $allowed — matches Redmine's
     * own ApplicationHelper#include_in_api_response? parsing.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private function parseIncludes(Request $request, array $allowed): array
    {
        $requested = collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $key) => trim($key))
            ->filter();

        return $requested->intersect($allowed)->values()->all();
    }

    /**
     * Loads children level by level until a level has none, so the resource
     * can nest them without lazy loading. The depth is capped as a guard
     * against a corrupt parent cycle.
     */
    private function loadDescendants(Issue $issue): void
    {
        $level = $issue->children;
        $tree = [];

        for ($depth = 0; $level->isNotEmpty() && $depth < 25; $depth++) {
            $tree = [...$tree, ...$level->all()];
            $level = new EloquentCollection($level->loadMissing('children')->pluck('children')->flatten(1)->all());
        }

        // The payload shows each issue's custom fields: one query for the lot.
        (new EloquentCollection($tree))->loadMissing('customFieldValues');
    }

    /**
     * @param  array<int, string>  $includes
     * @return array<int, string>
     */
    private function relationsToLoad(array $includes): array
    {
        return collect($includes)->flatMap(fn (string $include) => match ($include) {
            'journals' => ['journals.user', 'journals.updatedBy', 'journals.details'],
            'relations' => ['relationsFrom.to', 'relationsTo.from'],
            'attachments' => ['media'],
            'children' => ['children'],
            'watchers' => ['watchers.user'],
            'changesets' => ['changesets.repository.project'],
            default => [],
        })->all();
    }
}
