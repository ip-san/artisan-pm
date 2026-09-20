<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPageVersion;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Activity\MultiProjectActivityProvider;
use App\Support\Activity\OffByDefault;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

final class WikiActivityProvider implements MultiProjectActivityProvider, OffByDefault
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'wiki-edit';
    }

    public function label(): string
    {
        return 'Wiki';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        return $this->entriesForProjects(collect([$project]), $viewer, $from, $to);
    }

    public function entriesForProjects(Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        $projects = $projects->filter(fn (Project $project) => $this->authorization->can($viewer, 'view_wiki_edits', $project))->keyBy('id');

        if ($projects->isEmpty()) {
            return collect();
        }

        return WikiPageVersion::query()
            ->whereHas('wikiPage', fn ($query) => $query->whereIn('project_id', $projects->keys()))
            ->whereBetween('created_at', [$from, $to])
            ->with(['wikiPage', 'author'])
            ->get()
            ->map(fn (WikiPageVersion $version) => new ActivityEntry(
                type: $this->type(),
                title: "{$version->wikiPage->title} (v{$version->version})",
                url: route('wiki.show', [$projects[$version->wikiPage->project_id], $version->wikiPage]),
                authorName: $version->author->displayName(),
                occurredAt: $version->created_at ?? throw new LogicException('WikiPageVersion is missing created_at.'),
                authorId: $version->author_id,
            ));
    }
}
