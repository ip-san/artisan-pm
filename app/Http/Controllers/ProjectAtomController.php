<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Activity\ActivityEntry;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Redmine's `GET /projects.atom`: the projects the reader may see, newest
 * first, capped at Setting.feeds_limit — matches ProjectsController#index
 * responding to format.atom (`project_scope(:order => {:created_on => :desc})`).
 * No per-entry author, as in Redmine's Project.acts_as_event (:author => nil).
 */
final class ProjectAtomController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $visibleProjectIds = app(AuthorizationService::class)->visibleProjectIds($request->user(), 'view_project');

        $entries = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->latest('created_at')
            ->limit(ActivityFeedController::limit())
            ->get()
            ->map(fn (Project $project) => new ActivityEntry(
                type: 'project',
                title: __('プロジェクト: :name', ['name' => $project->name]),
                url: route('projects.show', $project),
                authorName: null,
                occurredAt: $project->created_at ?? now(),
            ));

        $xml = view('feeds.atom', [
            'entries' => $entries,
            'title' => (string) config('app.name').': '.__('最新のプロジェクト'),
            'alternateUrl' => route('projects.index'),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }
}
