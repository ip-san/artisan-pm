<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Activity\MultiProjectActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

final class NewsActivityProvider implements MultiProjectActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'news';
    }

    public function label(): string
    {
        return 'お知らせ';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        return $this->entriesForProjects(collect([$project]), $viewer, $from, $to);
    }

    public function entriesForProjects(Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        $projects = $projects->filter(fn (Project $project) => $this->authorization->can($viewer, 'view_news', $project))->keyBy('id');

        if ($projects->isEmpty()) {
            return collect();
        }

        return News::query()
            ->whereIn('project_id', $projects->keys())
            ->whereBetween('created_at', [$from, $to])
            ->with('author')
            ->get()
            ->map(fn (News $news) => new ActivityEntry(
                type: $this->type(),
                title: $news->title,
                url: route('news.show', [$projects[$news->project_id], $news]),
                authorName: $news->author->displayName(),
                occurredAt: $news->created_at ?? throw new LogicException('News is missing created_at.'),
                authorId: $news->author_id,
            ));
    }
}
