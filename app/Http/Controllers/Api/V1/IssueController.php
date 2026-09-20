<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\IssueTimeEntryDisposition;
use App\Exceptions\StaleIssueUpdateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIssueRequest;
use App\Http\Requests\Api\V1\UpdateIssueRequest;
use App\Http\Resources\Api\V1\IssueResource;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Api\CustomFieldPayload;
use App\Support\Issues\StartDateDefault;
use App\Support\Attachments\PendingUploadAttacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
     * Matches Redmine's own index action, which only ever honors
     * ?include=relations — every other key is show-only there too.
     *
     * @var array<int, string>
     */
    private const array INDEX_INCLUDES = ['relations'];

    /**
     * Only what the caller's role lets them see: view_issues on the project
     * plus the per-issue visibility tier (private issues, "own issues
     * only"), exactly as the issue list and Atom feed apply it.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        return $this->listIssues(
            $request,
            Issue::query()->where('project_id', $project->id)->visibleTo($request->user(), $project),
        );
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

        return $this->listIssues($request, Issue::query()->visibleToAcrossProjects($user, $projects));
    }

    /**
     * Shared by both indexes. Optional Redmine-style filters, all ANDed;
     * an unrecognised or malformed value is ignored rather than failing:
     * status_id (open, closed, * or an id — absent means every status,
     * unlike Redmine's open default, so existing clients see no change),
     * project_id (global index only matters there), tracker_id, priority_id,
     * category_id, fixed_version_id, parent_id, author_id and assigned_to_id
     * (`me` means the caller), and sort=column[:desc] over id, subject,
     * created_on and updated_on.
     *
     * @param  Builder<Issue>  $query
     */
    private function listIssues(Request $request, Builder $query): AnonymousResourceCollection
    {
        $this->applyIndexFilters($request, $query);

        // One query each for the child count and logged hours, instead of
        // one per issue when the resource asks isLeaf()/spentHours().
        $query->withCount('children')->withSum('timeEntries', 'hours');

        if (in_array('relations', $this->parseIncludes($request, self::INDEX_INCLUDES), true)) {
            $query->with(['relationsFrom.to', 'relationsTo.from']);
        }

        /** @var LengthAwarePaginator<int, Issue> $issues */
        $issues = $query->paginate();

        return IssueResource::collection($issues);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function applyIndexFilters(Request $request, Builder $query): void
    {
        $status = $request->query('status_id');

        if ($status === 'open' || $status === 'closed') {
            $query->whereHas('status', fn (Builder $q) => $q->where('is_closed', $status === 'closed'));
        } elseif (is_string($status) && ctype_digit($status)) {
            $query->where('status_id', (int) $status);
        }

        foreach (['project_id', 'tracker_id', 'priority_id', 'category_id', 'fixed_version_id', 'parent_id', 'author_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && ctype_digit($value)) {
                $query->where($column, (int) $value);
            }
        }

        $assignee = $request->query('assigned_to_id');

        if ($assignee === 'me') {
            $query->where('assigned_to_id', $request->user()->id);
        } elseif (is_string($assignee) && ctype_digit($assignee)) {
            $query->where('assigned_to_id', (int) $assignee);
        }

        $sort = $request->query('sort');
        $columns = ['id' => 'id', 'subject' => 'subject', 'created_on' => 'created_at', 'updated_on' => 'updated_at'];

        if (is_string($sort)) {
            [$name, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

            if (isset($columns[$name])) {
                $query->orderBy($columns[$name], $direction === 'desc' ? 'desc' : 'asc')->orderByDesc('id');

                return;
            }
        }

        $query->orderByDesc('id');
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
        $data = $request->validated();
        $uploads = $data['uploads'] ?? [];
        unset($data['uploads']);

        $customFieldData = CustomFieldPayload::extract(
            $request,
            (new Issue)->forceFill(['project_id' => $project->id, 'tracker_id' => $data['tracker_id']])->relevantCustomFields(),
            $request->user(),
            requireAll: true,
            project: $project,
        );

        $data['start_date'] = filled($data['start_date'] ?? null) ? $data['start_date'] : StartDateDefault::forApiAndMail();

        $issue = app(IssueService::class)->create(
            [...$data, 'project_id' => $project->id, 'status_id' => $this->defaultStatusId()],
            $request->user(),
            $customFieldData,
        );

        // Not journaled — an issue's creation itself isn't journaled
        // either, matching the web form's own reasoning for uploads
        // attached while creating vs. editing an issue.
        $this->attachUploads($issue, $uploads, journalize: false, actor: $request->user());

        return (new IssueResource($issue))->response()->setStatusCode(201);
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
        $data = $request->validated();
        $uploads = $data['uploads'] ?? [];
        $expectedLockVersion = isset($data['lock_version']) ? (int) $data['lock_version'] : null;
        unset($data['uploads'], $data['lock_version']);

        if (isset($data['status_id']) && $data['status_id'] !== $issue->status_id) {
            Gate::authorize('transitionTo', [$issue, IssueStatus::findOrFail($data['status_id'])]);
        }

        try {
            $customFieldData = CustomFieldPayload::extract($request, $issue->relevantCustomFields(), $request->user(), project: $issue->loadMissing('project')->project);
            $issue = app(IssueService::class)->update($issue, $data, $request->user(), customFieldData: $customFieldData, expectedLockVersion: $expectedLockVersion);
        } catch (StaleIssueUpdateException $exception) {
            return response()->json([
                'message' => '課題が他のユーザーによって更新されています。最新の内容を取得して、もう一度やり直してください。',
                'errors' => ['lock_version' => ['The issue was modified after the given lock_version was read.']],
                'lock_version' => $exception->issue->lock_version,
            ], 409);
        }

        $this->attachUploads($issue, $uploads, journalize: true, actor: $request->user());

        return new IssueResource($issue);
    }

    /**
     * Redmine's `todo` / `reassign_to_id` parameters choose what happens to
     * the issue's logged time. Omitting `todo` keeps the entries detached
     * from any issue (see IssueService::delete() for why that differs from
     * Redmine's delete-them default).
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
            IssueTimeEntryDisposition::tryFrom($data['todo'] ?? '') ?? IssueTimeEntryDisposition::Nullify,
            isset($data['reassign_to_id']) ? (int) $data['reassign_to_id'] : null,
        );

        return response()->json(status: 204);
    }

    private function defaultStatusId(): int
    {
        return IssueStatus::query()->orderBy('position')->value('id');
    }

    /**
     * Redeems each {token, filename?, description?} entry against
     * PendingUploadToken, moving the underlying Media onto this issue —
     * matches Redmine's Issue#save_attachments. An unknown/already-claimed
     * token is silently skipped rather than failing the whole request,
     * same as Redmine's own tolerant handling there.
     *
     * @param  array<int, array{token?: string, filename?: string, description?: string}>  $uploads
     */
    private function attachUploads(Issue $issue, array $uploads, bool $journalize, User $actor): void
    {
        $attached = [];

        foreach ($uploads as $upload) {
            $media = PendingUploadAttacher::attach($upload, $issue, 'attachments');

            if ($media !== null) {
                $attached[] = $media;
            }
        }

        // One journal — and one mail — for every file attached in this call.
        if ($journalize && $attached !== []) {
            app(IssueService::class)->journalizeAttachments($issue, $attached, added: true, actor: $actor);
        }
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
            'journals' => ['journals.user'],
            'relations' => ['relationsFrom.to', 'relationsTo.from'],
            'attachments' => ['media'],
            'children' => ['children'],
            'watchers' => ['watchers.user'],
            'changesets' => ['changesets.repository.project'],
            default => [],
        })->all();
    }
}
