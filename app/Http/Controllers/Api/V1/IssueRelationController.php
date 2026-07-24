<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIssueRelationRequest;
use App\Http\Resources\Api\V1\IssueRelationResource;
use App\Http\Resources\Api\V1\IssueResource;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Services\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Matches Redmine's IssueRelationsController: index/create nested under
 * an issue (GET/POST /issues/{issue}/relations), destroy flat
 * (DELETE /relations/{relation}), since a relation connects two issues
 * and isn't itself owned by one of them more than the other.
 *
 * index/show are gated on plain issue view access, not manage_issue_
 * relations — Redmine itself requires the manage permission for every
 * relations action including index, but this app's own Issue show
 * endpoint already exposes relations to any viewer via ?include=
 * relations (IssueResource::visibleRelations()), so gating this
 * dedicated endpoint more strictly would just contradict what the
 * Issue endpoint already discloses. store/destroy require
 * manageRelations, matching the web UI's addRelation()/deleteRelation().
 */
final class IssueRelationController extends Controller
{
    public function index(Request $request, Issue $issue): JsonResponse
    {
        Gate::authorize('view', $issue);

        $issue->load(['relationsFrom.to', 'relationsTo.from']);

        $relations = (new IssueResource($issue))->visibleRelations($issue, $request);

        return response()->json(['data' => $relations]);
    }

    public function store(StoreIssueRelationRequest $request, Issue $issue): JsonResponse
    {
        $data = $request->validated();

        // The FormRequest deliberately leaves visibility unchecked (see
        // its own docblock) so a nonexistent id and an existing-but-
        // invisible one produce distinct responses — this is the second
        // half of that two-step, matching the web UI's addRelation(),
        // which likewise validates first and authorizes 'view' on the
        // other issue second.
        $other = Issue::findOrFail($data['issue_to_id']);
        Gate::authorize('view', $other);

        $isSequential = in_array($data['relation_type'], ['precedes', 'follows'], true);

        $relation = IssueRelation::create([
            'issue_from_id' => $issue->id,
            'issue_to_id' => $data['issue_to_id'],
            'relation_type' => $data['relation_type'],
            'delay' => $isSequential ? ($data['delay'] ?? null) : null,
        ]);

        app(IssueService::class)->journalizeRelation($relation, added: true, actor: $request->user());
        app(IssueService::class)->rescheduleFromRelation($relation, $request->user());

        return (new IssueRelationResource($relation))->response()->setStatusCode(201);
    }

    /**
     * Unlike the web UI's deleteRelation() (which only checks
     * manageRelations against the issue the current page is scoped to),
     * this flat route has no such context — so, matching Redmine's own
     * broader IssueRelation#deletable? (permission on EITHER side's
     * project is sufficient), the requester may delete a relation they
     * can manage from either end.
     */
    public function destroy(Request $request, IssueRelation $relation): JsonResponse
    {
        $user = $request->user();
        $canManage = ($relation->from !== null && $user->can('manageRelations', $relation->from))
            || ($relation->to !== null && $user->can('manageRelations', $relation->to));

        abort_unless($canManage, 403);

        $relation->delete();
        app(IssueService::class)->journalizeRelation($relation, added: false, actor: $user);

        return response()->json(status: 204);
    }
}
