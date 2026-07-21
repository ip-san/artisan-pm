<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

final class IssueActivityProvider implements ActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'issue';
    }

    public function label(): string
    {
        return '課題';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_issues', $project)) {
            return collect();
        }

        return Issue::query()
            ->where('project_id', $project->id)
            ->whereBetween('created_at', [$from, $to])
            ->with(['tracker', 'author'])
            ->get()
            ->map(fn (Issue $issue) => new ActivityEntry(
                type: $this->type(),
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$project, $issue]),
                authorName: $issue->author->name,
                occurredAt: $issue->created_at ?? throw new LogicException('Issue is missing created_at.'),
            ));
    }
}
