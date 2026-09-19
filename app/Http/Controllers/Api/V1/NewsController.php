<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNewsRequest;
use App\Http\Requests\Api\V1\UpdateNewsRequest;
use App\Http\Resources\Api\V1\NewsResource;
use App\Events\NewsCommentCreated;
use App\Http\Resources\Api\V1\NewsCommentResource;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Project-nested index/store, plus Redmine's project-less GET /news.json
 * (globalIndex), which lists news of every project the caller may view
 * news in, newest first, and Redmine's comment endpoints (storeComment /
 * destroyComment).
 */
final class NewsController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [News::class, $project]);

        $newsItems = News::query()
            ->where('project_id', $project->id)
            ->withCount('comments')
            ->orderByDesc('created_at')
            ->paginate();

        return NewsResource::collection($newsItems);
    }

    /**
     * Project visibility, the news module and view_news are per-project
     * decisions that cannot be written as one SQL clause, so they are
     * resolved once per project that has news at all (as the web global list
     * does); pagination then stays in SQL.
     */
    public function globalIndex(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $visibleProjectIds = Project::query()
            ->whereIn('id', News::query()->distinct()->pluck('project_id'))
            ->get()
            ->filter(fn (Project $project) => $user->can('viewAny', [News::class, $project]))
            ->pluck('id');

        $query = News::query()->whereIn('project_id', $visibleProjectIds)->withCount('comments');

        $projectId = $request->query('project_id');

        if (is_string($projectId) && ctype_digit($projectId)) {
            $query->where('project_id', (int) $projectId);
        }

        return NewsResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate());
    }

    public function show(News $news): NewsResource
    {
        Gate::authorize('view', $news);

        return new NewsResource($news->loadCount('comments'));
    }

    public function store(StoreNewsRequest $request, Project $project): JsonResponse
    {
        $news = new News($request->validated());
        $news->project()->associate($project);
        $news->author()->associate($request->user());
        $news->save();

        return (new NewsResource($news->loadCount('comments')))->response()->setStatusCode(201);
    }

    /**
     * POST /news/{news}/comments — needs comment_news, like the web form.
     */
    public function storeComment(Request $request, News $news): JsonResponse
    {
        Gate::authorize('comment', $news);

        $data = $request->validate(['content' => ['required', 'string']]);

        $comment = NewsComment::create([
            'news_id' => $news->id,
            'author_id' => $request->user()->id,
            'content' => $data['content'],
        ]);

        NewsCommentCreated::dispatch($comment);

        return (new NewsCommentResource($comment))->response()->setStatusCode(201);
    }

    /**
     * DELETE /news/{news}/comments/{comment} — manage_news, as in Redmine;
     * a comment is only reachable through its own news item.
     */
    public function destroyComment(News $news, NewsComment $comment): JsonResponse
    {
        abort_unless($comment->news_id === $news->id, 404);

        Gate::authorize('delete', $comment);

        $comment->delete();

        return response()->json(status: 204);
    }

    public function update(UpdateNewsRequest $request, News $news): NewsResource
    {
        $news->update($request->validated());

        return new NewsResource($news->loadCount('comments'));
    }

    public function destroy(News $news): JsonResponse
    {
        Gate::authorize('delete', $news);

        $news->delete();

        return response()->json(status: 204);
    }
}
