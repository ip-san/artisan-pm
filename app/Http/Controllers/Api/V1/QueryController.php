<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\QueryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexQueryRequest;
use App\Http\Resources\Api\V1\QueryResource;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query;
use App\Models\TimeEntry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Redmine本家の QueriesController#index はフィルタ実行を伴わない、保存済み
 * クエリの一覧のみ(id/name/is_public/project_id)を返す — 本アプリもその
 * 最小スコープのみを実装し、フィルタ/カラム/ソート条件の実行やCRUDは対象外。
 * `Query::visibleIn()`/`visibleGlobally()`自体はプロジェクト単位の閲覧権限
 * (Redmine本家の`Project.allowed_to_condition`相当)を考慮しないため、
 * `project_id`指定時は`type`に応じた閲覧権限(view_issues/view_time_entries)
 * をここで明示的にゲートする。
 *
 * `project_id`省略時(グローバル一覧)には意図的にこのゲートを適用しない —
 * Redmine本家の`Query.visible`スコープ自体が`project_id IS NULL OR
 * <allowed_to_condition>`というSQLで、project_id が NULL の行は
 * プロジェクト権限チェックを素通りする設計のため(グローバルな保存済み
 * クエリは特定プロジェクトに紐付かず、`view_issues`/`view_time_entries`を
 * 判定する対象のプロジェクトが存在しない)。ここで開示されるのは公開/
 * 閲覧可能なクエリの名前・IDのみで、フィルタ内容や実行結果(実際の課題/
 * 工数データ)ではない。
 */
final class QueryController extends Controller
{
    public function index(IndexQueryRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $type = isset($data['type']) ? QueryType::from($data['type']) : QueryType::Issue;

        if (isset($data['project_id'])) {
            $project = Project::find($data['project_id']);

            if ($project === null) {
                throw ValidationException::withMessages(['project_id' => 'The selected project id is invalid.']);
            }

            match ($type) {
                QueryType::Issue => Gate::authorize('viewAny', [Issue::class, $project]),
                QueryType::TimeEntry => Gate::authorize('viewAny', [TimeEntry::class, $project]),
            };

            $queries = Query::visibleIn($project, $type, $request->user());
        } else {
            $queries = Query::visibleGlobally($type, $request->user());
        }

        return QueryResource::collection($queries);
    }
}
