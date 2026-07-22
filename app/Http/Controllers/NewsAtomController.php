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
 * news item in this project, newest first. Capped the same way the
 * project activity feed and board Atom feeds are (shared
 * ActivityFeedController::LIMIT) rather than exposing Setting.feeds_limit
 * as a configurable value.
 */
final class NewsAtomController extends Controller
{
    public function __invoke(Project $project): Response
    {
        Gate::authorize('viewAny', [News::class, $project]);

        $entries = $project->news()
            ->with('author')
            ->latest('id')
            ->limit(ActivityFeedController::LIMIT)
            ->get()
            ->map(fn (News $news) => new ActivityEntry(
                type: 'news',
                title: $news->title,
                url: route('news.show', [$project, $news]),
                authorName: $news->author->name,
                occurredAt: $news->created_at ?? throw new LogicException('News is missing created_at.'),
            ));

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => "{$project->name}: News - ".config('app.name'),
            'alternateUrl' => route('news.index', $project),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
