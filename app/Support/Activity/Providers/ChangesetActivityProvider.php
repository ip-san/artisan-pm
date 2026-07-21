<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Changeset;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ChangesetActivityProvider implements ActivityProvider
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
        return 'リポジトリ';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_changesets', $project)) {
            return collect();
        }

        return Changeset::query()
            ->whereHas('repository', fn ($query) => $query->where('project_id', $project->id))
            ->whereBetween('committed_on', [$from, $to])
            ->get()
            ->map(fn (Changeset $changeset) => new ActivityEntry(
                type: $this->type(),
                title: "{$changeset->shortRevision()}: ".Str::of((string) $changeset->comments)->trim()->limit(80),
                url: route('repository.show', [$project, $changeset]),
                authorName: $changeset->committer,
                occurredAt: $changeset->committed_on,
            ));
    }
}
