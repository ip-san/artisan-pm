<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\Project;
use App\Support\Activity\ActivityEntry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Matches Redmine's NewsController#index responding to format.atom: every
 * news item in this project — or, without one (`/news.atom`, A15-16), every
 * news item across every project the reader may view_news in — newest
 * first. Capped by Setting::feeds_limit like every other Atom feed (see
 * ActivityFeedController::limit()).
 */
final class NewsAtomController extends Controller
{
    public function __invoke(?Project $project = null): Response
    {
        if ($project !== null) {
            Gate::authorize('viewAny', [News::class, $project]);
        }

        $query = News::query()->with(['author', 'project']);

        if ($project !== null) {
            $query->where('project_id', $project->id);
        } else {
            $visibleProjectIds = Project::query()
                ->whereIn('id', News::query()->distinct()->pluck('project_id'))
                ->get()
                ->filter(fn (Project $candidate) => Gate::allows('viewAny', [News::class, $candidate]))
                ->pluck('id');

            $query->whereIn('project_id', $visibleProjectIds);
        }

        $entries = $query
            ->latest('id')
            ->limit(ActivityFeedController::limit())
            ->get()
            ->map(fn (News $news) => new ActivityEntry(
                type: 'news',
                title: $project !== null ? $news->title : "{$news->project->name}: {$news->title}",
                url: route('news.show', [$news->project, $news]),
                authorName: $news->author->displayName(),
                occurredAt: $news->created_at ?? throw new LogicException('News is missing created_at.'),
            ));

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => ($project !== null ? $project->name : (string) config('app.name')).': News',
            'alternateUrl' => $project !== null ? route('news.index', $project) : route('news.global-index'),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
