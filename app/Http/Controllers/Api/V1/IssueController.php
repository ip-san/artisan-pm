<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIssueRequest;
use App\Http\Requests\Api\V1\UpdateIssueRequest;
use App\Http\Resources\Api\V1\IssueResource;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\PendingUpload;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Attachments\PendingUploadToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class IssueController extends Controller
{
    /**
     * The relations show's ?include= can request — Redmine's own keys,
     * minus allowed_statuses (needs the workflow engine) and changesets
     * (needs SCM linkage), both left out of scope here.
     *
     * @var array<int, string>
     */
    private const array SHOW_INCLUDES = ['journals', 'relations', 'attachments', 'children', 'watchers'];

    /**
     * Matches Redmine's own index action, which only ever honors
     * ?include=relations — every other key is show-only there too.
     *
     * @var array<int, string>
     */
    private const array INDEX_INCLUDES = ['relations'];

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Issue::class, $project]);

        $query = Issue::query()->where('project_id', $project->id)->orderByDesc('id');

        if (in_array('relations', $this->parseIncludes($request, self::INDEX_INCLUDES), true)) {
            $query->with(['relationsFrom.to', 'relationsTo.from']);
        }

        /** @var LengthAwarePaginator<int, Issue> $issues */
        $issues = $query->paginate();

        return IssueResource::collection($issues);
    }

    public function show(Request $request, Issue $issue): IssueResource
    {
        Gate::authorize('view', $issue);

        $issue->load($this->relationsToLoad($this->parseIncludes($request, self::SHOW_INCLUDES)));

        return new IssueResource($issue);
    }

    public function store(StoreIssueRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $uploads = $data['uploads'] ?? [];
        unset($data['uploads']);

        $issue = app(IssueService::class)->create(
            [...$data, 'project_id' => $project->id, 'status_id' => $this->defaultStatusId()],
            $request->user(),
        );

        // Not journaled — an issue's creation itself isn't journaled
        // either, matching the web form's own reasoning for uploads
        // attached while creating vs. editing an issue.
        $this->attachUploads($issue, $uploads, journalize: false, actor: $request->user());

        return (new IssueResource($issue))->response()->setStatusCode(201);
    }

    public function update(UpdateIssueRequest $request, Issue $issue): IssueResource
    {
        $data = $request->validated();
        $uploads = $data['uploads'] ?? [];
        unset($data['uploads']);

        if (isset($data['status_id']) && $data['status_id'] !== $issue->status_id) {
            Gate::authorize('transitionTo', [$issue, IssueStatus::findOrFail($data['status_id'])]);
        }

        $issue = app(IssueService::class)->update($issue, $data, $request->user());

        $this->attachUploads($issue, $uploads, journalize: true, actor: $request->user());

        return new IssueResource($issue);
    }

    public function destroy(Issue $issue): JsonResponse
    {
        Gate::authorize('delete', $issue);

        app(IssueService::class)->delete($issue);

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
        foreach ($uploads as $upload) {
            $media = PendingUploadToken::resolve((string) ($upload['token'] ?? ''));

            if ($media === null) {
                continue;
            }

            $pendingUploadId = $media->model_id;
            $filename = trim((string) ($upload['filename'] ?? ''));
            $description = trim((string) ($upload['description'] ?? ''));

            // Custom properties carry over through move() (it's a
            // copy+delete under the hood — see Media::copy()), so the
            // description has to be set on the pre-move instance.
            if ($description !== '') {
                $media->setCustomProperty('description', $description);
                $media->save();
            }

            $media = $media->move($issue, 'attachments', '', $filename);

            PendingUpload::query()->whereKey($pendingUploadId)->delete();

            if ($journalize) {
                app(IssueService::class)->journalizeAttachment($issue, $media, added: true, actor: $actor);
            }
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
            default => [],
        })->all();
    }
}
