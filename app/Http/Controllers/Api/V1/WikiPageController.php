<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWikiPageRequest;
use App\Http\Requests\Api\V1\UpdateWikiPageRequest;
use App\Http\Resources\Api\V1\WikiPageDetailResource;
use App\Http\Resources\Api\V1\WikiPageResource;
use App\Models\Project;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Redmine本家はタイトルをURLセグメントとして扱い(`/wiki/{title}.json`)、
 * PUTがcreate-or-updateを兼ねる(新規なら201+本文、既存なら200+空ボディ)が、
 * 本アプリはWeb UIのルーティング規約(数値ID route-model-binding、
 * `Volt::route('/projects/{project:identifier}/wiki/{wikiPage}', ...)`)
 * に合わせ、このAPIリソースも数値IDで扱う。store/updateは他リソースと
 * 同じ通常のREST動詞に分離し、本家のcreate-or-updateセマンティクスは
 * 踏襲しない。
 */
final class WikiPageController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [WikiPage::class, $project]);

        $pages = WikiPage::query()
            ->where('project_id', $project->id)
            ->with('currentVersion')
            ->orderBy('title')
            ->paginate();

        return WikiPageResource::collection($pages);
    }

    public function show(Request $request, WikiPage $wikiPage): WikiPageDetailResource
    {
        Gate::authorize('view', $wikiPage);

        $version = null;

        if ($request->filled('version')) {
            // Redmine本家は?version=閲覧に別途view_wiki_edits権限を要求するが、
            // 本アプリのWikiPagePolicyにはWiki編集履歴専用の権限が存在しない
            // ため、通常の閲覧権限(view_wiki_pages、上のview判定と同じ)のみで
            // 判定する。
            $version = $wikiPage->versions()->where('version', $request->integer('version'))->firstOrFail();
        }

        return new WikiPageDetailResource($wikiPage, $version);
    }

    public function store(StoreWikiPageRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $text = $data['text'];
        unset($data['text']);

        $page = app(WikiPageService::class)->create($project, $data, $text, $request->user());

        return (new WikiPageDetailResource($page))->response()->setStatusCode(201);
    }

    public function update(UpdateWikiPageRequest $request, WikiPage $wikiPage): WikiPageDetailResource
    {
        $data = $request->validated();
        $text = $data['text'] ?? $wikiPage->currentVersion->text;
        $comment = $data['comments'] ?? null;
        unset($data['text'], $data['comments']);

        $page = app(WikiPageService::class)->update($wikiPage, $data, $text, $request->user(), $comment);

        return new WikiPageDetailResource($page);
    }

    public function destroy(WikiPage $wikiPage): JsonResponse
    {
        Gate::authorize('delete', $wikiPage);

        app(WikiPageService::class)->delete($wikiPage);

        return response()->json(status: 204);
    }
}
