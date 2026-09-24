<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Changeset;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\LastActivityProvider;
use App\Support\Activity\MultiProjectActivityProvider;
use App\Support\Activity\ProjectLastActivity;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ChangesetActivityProvider implements LastActivityProvider, MultiProjectActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'changeset';
    }

    public function label(): string
    {
        return __('リポジトリ');
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        return $this->entriesForProjects(collect([$project]), $viewer, $from, $to);
    }

    public function entriesForProjects(Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        $projects = $projects->filter(fn (Project $project) => $this->authorization->can($viewer, 'view_changesets', $project))->keyBy('id');

        if ($projects->isEmpty()) {
            return collect();
        }

        return Changeset::query()
            ->whereHas('repository', fn ($query) => $query->whereIn('project_id', $projects->keys()))
            ->whereBetween('committed_on', [$from, $to])
            ->with('repository')
            ->get()
            ->map(fn (Changeset $changeset) => new ActivityEntry(
                type: $this->type(),
                title: "{$changeset->shortRevision()}: ".Str::of((string) $changeset->comments)->trim()->limit(80),
                url: route($changeset->repository->routeName('repository.show'), $changeset->repository->routeParameters(['changeset' => $changeset])),
                authorName: $changeset->committer,
                occurredAt: $changeset->committed_on,
            ));
    }

    public function lastActivityByProject(Collection $projects, ?User $viewer): Collection
    {
        $projects = $projects->filter(fn (Project $project) => $this->authorization->can($viewer, 'view_changesets', $project))->values();

        if ($projects->isEmpty()) {
            return collect();
        }

        return ProjectLastActivity::maxByProject(
            Changeset::query()
                ->join('repositories', 'repositories.id', '=', 'changesets.repository_id')
                ->whereIn('repositories.project_id', $projects->pluck('id')),
            'repositories.project_id',
            'changesets.committed_on',
        );
    }
}
