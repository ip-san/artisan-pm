<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

final class DocumentActivityProvider implements ActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'document';
    }

    public function label(): string
    {
        return '文書';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_documents', $project)) {
            return collect();
        }

        return Document::query()
            ->where('project_id', $project->id)
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->map(fn (Document $document) => new ActivityEntry(
                type: $this->type(),
                title: $document->title,
                url: route('documents.show', [$project, $document]),
                // Document has no author column — matches Redmine, where
                // documents aren't attributed to a specific uploader either.
                authorName: null,
                occurredAt: $document->created_at ?? throw new LogicException('Document is missing created_at.'),
            ));
    }
}
