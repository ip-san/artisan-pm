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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Searches across every searchable module in one project, combining
 * results into a single ranked-by-recency list. Every type is queried
 * directly (LIKE over the same columns each model's toSearchableArray()
 * declares) rather than through Scout: Redmine's all-words/any-word
 * matching and titles-only scope need per-word, per-column clauses that
 * Scout's single-string database engine can't express. The Searchable
 * traits stay on the models — they're the contract for a future switch
 * to a real search engine (plan §1), at which point this service is the
 * one place to reroute.
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
    public function search(Project $project, ?User $viewer, string $query, bool $allWords = true, bool $titlesOnly = false, bool $openIssuesOnly = false): Collection
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return collect();
        }

        return collect()
            ->merge($this->searchIssues($project, $viewer, $words, $allWords, $titlesOnly, $openIssuesOnly))
            ->merge($this->searchWikiPages($project, $viewer, $words, $allWords, $titlesOnly))
            ->merge($this->searchNews($project, $viewer, $words, $allWords, $titlesOnly))
            ->merge($this->searchDocuments($project, $viewer, $words, $allWords, $titlesOnly))
            ->merge($this->searchMessages($project, $viewer, $words, $allWords, $titlesOnly))
            ->sortByDesc('updatedAt')
            ->values();
    }

    /**
     * Word-level matching: with $allWords (Redmine's all_words, the
     * default) every word must appear in at least one of $columns; with
     * any-word matching a single word match suffices.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $builder
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $words
     * @return Builder<TModel>
     */
    private function whereWordsMatch(Builder $builder, array $columns, array $words, bool $allWords): Builder
    {
        return $builder->where(function (Builder $outer) use ($columns, $words, $allWords) {
            foreach ($words as $wordIndex => $word) {
                $outer->where(function (Builder $inner) use ($columns, $word) {
                    foreach ($columns as $columnIndex => $column) {
                        $inner->where($column, 'like', "%{$word}%", $columnIndex === 0 ? 'and' : 'or');
                    }
                }, boolean: ($allWords || $wordIndex === 0) ? 'and' : 'or');
            }
        });
    }

    /**
     * Combines the subject/description word match with a LIKE search
     * over searchable custom field values (a separate EAV table), merged
     * by id. Custom field values are skipped in titles-only mode — they
     * are body content, not titles.
     *
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchIssues(Project $project, ?User $viewer, array $words, bool $allWords, bool $titlesOnly, bool $openIssuesOnly): Collection
    {
        if (! $this->authorization->can($viewer, 'view_issues', $project)) {
            return collect();
        }

        $matchedIds = $this->whereWordsMatch(
            Issue::query()->where('project_id', $project->id),
            $titlesOnly ? ['subject'] : ['subject', 'description'],
            $words,
            $allWords,
        )->limit(self::RESULTS_PER_TYPE)->pluck('id');

        if (! $titlesOnly) {
            $matchedIds = $matchedIds->merge($this->issueIdsMatchingSearchableCustomFields($project, $words, $allWords))->unique();
        }

        return Issue::query()
            ->whereIn('id', $matchedIds)
            ->when($openIssuesOnly, fn (Builder $query) => $query->whereHas('status', fn ($status) => $status->where('is_closed', false)))
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
     * @param  array<int, string>  $words
     * @return Collection<int, int>
     */
    private function issueIdsMatchingSearchableCustomFields(Project $project, array $words, bool $allWords): Collection
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
        return $this->whereWordsMatch(
            CustomFieldValue::query()
                ->where('customized_type', CustomizableType::Issue)
                ->whereIn('custom_field_id', $searchableFieldIds)
                ->whereIn('customized_id', fn ($q) => $q->select('id')->from('issues')->where('project_id', $project->id)),
            ['value_string', 'value_text'],
            $words,
            $allWords,
        )->pluck('customized_id');
    }

    /**
     * The wiki page body lives on a related model (currentVersion), so
     * the per-word clause is built by hand instead of via
     * whereWordsMatch(): each word matches the title or, outside
     * titles-only mode, the current version's text.
     *
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchWikiPages(Project $project, ?User $viewer, array $words, bool $allWords, bool $titlesOnly): Collection
    {
        if (! $this->authorization->can($viewer, 'view_wiki_pages', $project)) {
            return collect();
        }

        return WikiPage::query()
            ->where('project_id', $project->id)
            ->where(function (Builder $outer) use ($words, $allWords, $titlesOnly) {
                foreach ($words as $wordIndex => $word) {
                    $outer->where(function (Builder $inner) use ($word, $titlesOnly) {
                        $inner->where('title', 'like', "%{$word}%");

                        if (! $titlesOnly) {
                            $inner->orWhereHas('currentVersion', fn ($version) => $version->where('text', 'like', "%{$word}%"));
                        }
                    }, boolean: ($allWords || $wordIndex === 0) ? 'and' : 'or');
                }
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
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchNews(Project $project, ?User $viewer, array $words, bool $allWords, bool $titlesOnly): Collection
    {
        if (! $this->authorization->can($viewer, 'view_news', $project)) {
            return collect();
        }

        return $this->whereWordsMatch(
            News::query()->where('project_id', $project->id),
            $titlesOnly ? ['title'] : ['title', 'summary', 'description'],
            $words,
            $allWords,
        )
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
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchDocuments(Project $project, ?User $viewer, array $words, bool $allWords, bool $titlesOnly): Collection
    {
        if (! $this->authorization->can($viewer, 'view_documents', $project)) {
            return collect();
        }

        return $this->whereWordsMatch(
            Document::query()->where('project_id', $project->id),
            $titlesOnly ? ['title'] : ['title', 'description'],
            $words,
            $allWords,
        )
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
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchMessages(Project $project, ?User $viewer, array $words, bool $allWords, bool $titlesOnly): Collection
    {
        if (! $this->authorization->can($viewer, 'view_messages', $project)) {
            return collect();
        }

        return $this->whereWordsMatch(
            Message::query()->whereHas('board', fn ($board) => $board->where('project_id', $project->id)),
            $titlesOnly ? ['subject'] : ['subject', 'content'],
            $words,
            $allWords,
        )
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
