<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNewsRequest;
use App\Http\Requests\Api\V1\UpdateNewsRequest;
use App\Http\Resources\Api\V1\NewsResource;
use App\Models\News;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Project-nested index/store only — Redmine本家はproject_id省略時に
 * GET /news.jsonで全プロジェクト横断一覧も返すが、本アプリの他リソースは
 * indexが全てproject-nested限定のため、一貫性を優先しグローバル一覧は
 * 対象外とする。
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
