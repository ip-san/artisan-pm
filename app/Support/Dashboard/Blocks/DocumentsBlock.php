<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Document;
use App\Models\User;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use Illuminate\Support\Collection;

final class DocumentsBlock implements DashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return '最新の文書';
    }

    /**
     * Scoped to projects the user is a member of, rather than per-project
     * view_documents permission checks — same reasonable proxy
     * LatestNewsBlock already uses for a dashboard summary block, not a
     * substitute for the project's own documents page, which still
     * enforces the real permission.
     */
    public function rows(User $user): Collection
    {
        $projectIds = $user->projects()->pluck('projects.id');

        return Document::query()
            ->whereIn('project_id', $projectIds)
            ->with('project')
            ->latest()
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Document $document) => new DashboardBlockRow(
                title: "{$document->project->name}: {$document->title}",
                url: route('documents.show', [$document->project, $document]),
                meta: $document->created_at->toDateString(),
            ));
    }
}
