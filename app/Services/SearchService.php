<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Authorization\AuthorizationService;
use App\Support\Search\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Searches across every searchable module in one project, combining
 * results into a single ranked-by-recency list. Issue/News/Document/
 * Message go through Scout's database engine (no separate index — it
 * queries each model's own table directly via LIKE, so results are
 * always current). WikiPage is a plain query instead: the actual
 * searchable content (its current version's text) lives on
 * WikiPageVersion, a separate table Scout's database engine can't reach
 * through toSearchableArray() since that only maps to real columns on
 * the searched model's own table.
 *
 * A single service rather than a provider-per-type registry (unlike
 * ActivityProvider) because every type here follows the same "search,
 * map to a DTO" shape — Activity's providers diverge much more (date
 * ranges, private-notes filtering, per-type title formatting), which is
 * what actually justified that registry.
 */
final class SearchService
{
    private const int RESULTS_PER_TYPE = 20;

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @return Collection<int, SearchResult>
     */
    public function search(Project $project, ?User $viewer, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        return collect()
            ->merge($this->searchIssues($project, $viewer, $query))
            ->merge($this->searchWikiPages($project, $viewer, $query))
            ->merge($this->searchNews($project, $viewer, $query))
            ->merge($this->searchDocuments($project, $viewer, $query))
            ->merge($this->searchMessages($project, $viewer, $query))
            ->sortByDesc('updatedAt')
            ->values();
    }

    /**
     * Combines Scout's subject/description match with a direct LIKE search
     * over searchable custom field values — Scout's database engine can
     * only match real columns on the searched model's own table via
     * toSearchableArray(), so custom field values (a separate EAV table)
     * need their own query merged in by id.
     *
     * @return Collection<int, SearchResult>
     */
    private function searchIssues(Project $project, ?User $viewer, string $query): Collection
    {
        if (! $this->authorization->can($viewer, 'view_issues', $project)) {
            return collect();
        }

        $matchedIds = Issue::search($query)
            ->where('project_id', $project->id)
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->pluck('id')
            ->merge($this->issueIdsMatchingSearchableCustomFields($project, $query))
            ->unique();

        return Issue::query()
            ->whereIn('id', $matchedIds)
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Issue $issue) => new SearchResult(
                type: 'issue',
                title: "#{$issue->id} {$issue->subject}",
                url: route('issues.show', [$project, $issue]),
                excerpt: $this->excerpt($issue->description),
                updatedAt: $issue->updated_at,
            ));
    }

    /**
     * @return Collection<int, int>
     */
    private function issueIdsMatchingSearchableCustomFields(Project $project, string $query): Collection
    {
        $searchableFieldIds = CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->where('searchable', true)
            ->pluck('id');

        if ($searchableFieldIds->isEmpty()) {
            return collect();
        }

        // Only value_string/value_text are searched — a LIKE against an
        // int/float/date/bool field's own storage column wouldn't be a
        // meaningful text match, and those formats leave value_string/
        // value_text NULL anyway, so this naturally excludes them without
        // needing to branch on field_format.
        return CustomFieldValue::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereIn('custom_field_id', $searchableFieldIds)
            ->where(fn ($q) => $q->where('value_string', 'like', "%{$query}%")->orWhere('value_text', 'like', "%{$query}%"))
            ->whereIn('customized_id', fn ($q) => $q->select('id')->from('issues')->where('project_id', $project->id))
            ->pluck('customized_id');
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function searchWikiPages(Project $project, ?User $viewer, string $query): Collection
    {
        if (! $this->authorization->can($viewer, 'view_wiki_pages', $project)) {
            return collect();
        }

        return WikiPage::query()
            ->where('project_id', $project->id)
            ->where(function ($builder) use ($query) {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhereHas('currentVersion', fn ($version) => $version->where('text', 'like', "%{$query}%"));
            })
            ->with('currentVersion')
            ->limit(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (WikiPage $page) => new SearchResult(
                type: 'wiki-page',
                title: $page->title,
                url: route('wiki.show', [$project, $page]),
                excerpt: $this->excerpt($page->currentVersion?->text),
                updatedAt: $page->currentVersion->created_at,
            ));
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function searchNews(Project $project, ?User $viewer, string $query): Collection
    {
        if (! $this->authorization->can($viewer, 'view_news', $project)) {
            return collect();
        }

        return News::search($query)
            ->where('project_id', $project->id)
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (News $news) => new SearchResult(
                type: 'news',
                title: $news->title,
                url: route('news.show', [$project, $news]),
                excerpt: $this->excerpt($news->description),
                updatedAt: $news->updated_at,
            ));
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function searchDocuments(Project $project, ?User $viewer, string $query): Collection
    {
        if (! $this->authorization->can($viewer, 'view_documents', $project)) {
            return collect();
        }

        return Document::search($query)
            ->where('project_id', $project->id)
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Document $document) => new SearchResult(
                type: 'document',
                title: $document->title,
                url: route('documents.show', [$project, $document]),
                excerpt: $this->excerpt($document->description),
                updatedAt: $document->updated_at,
            ));
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function searchMessages(Project $project, ?User $viewer, string $query): Collection
    {
        if (! $this->authorization->can($viewer, 'view_messages', $project)) {
            return collect();
        }

        return Message::search($query)
            ->query(fn ($builder) => $builder->whereHas('board', fn ($board) => $board->where('project_id', $project->id)))
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->load('board')
            ->map(fn (Message $message) => new SearchResult(
                type: 'message',
                title: $message->isTopic() ? $message->subject : "RE: {$message->subject}",
                url: route('messages.show', [$project, $message->board, $message->isTopic() ? $message : $message->parent_id]),
                excerpt: $this->excerpt($message->content),
                updatedAt: $message->updated_at,
            ));
    }

    private function excerpt(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return Str::limit(Str::squish($text), 160);
    }
}
