<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentSearchMode;
use App\Enums\CustomizableType;
use App\Models\Changeset;
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
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Searches across every searchable module in one or more projects,
 * combining results into a single ranked-by-recency list. Every type is
 * queried directly (LIKE over the same columns each model's
 * toSearchableArray() declares) rather than through Scout: Redmine's
 * all-words/any-word matching and titles-only scope need per-word,
 * per-column clauses that Scout's single-string database engine can't
 * express. The Searchable traits stay on the models — they're the
 * contract for a future switch to a real search engine (plan §1), at
 * which point this service is the one place to reroute.
 *
 * A single service rather than a provider-per-type registry (unlike
 * ActivityProvider) because every type here follows the same "search,
 * map to a DTO" shape — Activity's providers diverge much more (date
 * ranges, private-notes filtering, per-type title formatting), which is
 * what actually justified that registry.
 *
 * search() (single project) and searchAcrossProjects() (a pre-filtered
 * collection of projects the viewer can search in, matching how
 * issues.global-index/time-entries.global-index resolve visible
 * projects) both delegate to the same per-type methods below — those
 * take a Collection<Project> throughout, with search() just wrapping its
 * one project in a single-item collection.
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
    public function search(Project $project, ?User $viewer, string $query, bool $allWords = true, bool $titlesOnly = false, bool $openIssuesOnly = false, AttachmentSearchMode $attachments = AttachmentSearchMode::Exclude): Collection
    {
        return $this->searchAcrossProjects(collect([$project]), $viewer, $query, $allWords, $titlesOnly, $openIssuesOnly, $attachments);
    }

    /**
     * @param  Collection<int, Project>  $projects  every project the
     *                                              viewer is allowed to search in at all — this only narrows
     *                                              further per type (a viewer might hold view_issues but not
     *                                              view_wiki_pages in the same project, for instance).
     * @return Collection<int, SearchResult>
     */
    public function searchAcrossProjects(Collection $projects, ?User $viewer, string $query, bool $allWords = true, bool $titlesOnly = false, bool $openIssuesOnly = false, AttachmentSearchMode $attachments = AttachmentSearchMode::Exclude): Collection
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || $projects->isEmpty()) {
            return collect();
        }

        // Changesets and projects have no attachments, so searching through
        // attachments only leaves them out (Redmine's acts_as_searchable).
        $hasOwnText = $attachments->searchesOwnText();

        return collect()
            ->merge($this->searchIssues($projects, $viewer, $words, $allWords, $titlesOnly, $openIssuesOnly, $attachments))
            ->merge($this->searchWikiPages($projects, $viewer, $words, $allWords, $titlesOnly, $attachments))
            ->merge($this->searchNews($projects, $viewer, $words, $allWords, $titlesOnly, $attachments))
            ->merge($this->searchDocuments($projects, $viewer, $words, $allWords, $titlesOnly, $attachments))
            ->merge($this->searchMessages($projects, $viewer, $words, $allWords, $titlesOnly, $attachments))
            ->merge($hasOwnText ? $this->searchChangesets($projects, $viewer, $words, $allWords) : [])
            ->merge($hasOwnText ? $this->searchProjects($projects, $words, $allWords, $titlesOnly) : [])
            ->sortByDesc('updatedAt')
            ->values();
    }

    /**
     * The ids of records whose attachment file name or description match
     * — a subquery over the media table, scoped to the given owner rows
     * so it never scans other projects' files. `$morph` is the owner's
     * morph-map alias.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $owners
     * @param  array<int, string>  $words
     * @return Builder<Media>
     */
    private function attachmentOwnerIds(string $morph, Builder $owners, array $words, bool $allWords): Builder
    {
        return $this->whereWordsMatch(
            Media::query()
                ->select('model_id')
                ->where('model_type', $morph)
                ->where('collection_name', 'attachments')
                ->whereIn('model_id', $owners->clone()->select('id')),
            ['file_name', 'custom_properties->description'],
            $words,
            $allWords,
        );
    }

    /**
     * The per-type word match, widened or replaced by an attachment match
     * according to $attachments — for types whose own text is matched by
     * plain columns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $base
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $words
     * @return Builder<TModel>
     */
    private function whereMatchesOrHasMatchingAttachment(Builder $base, string $morph, array $columns, array $words, bool $allWords, bool $titlesOnly, AttachmentSearchMode $attachments): Builder
    {
        if (! $attachments->searchesAttachments($titlesOnly)) {
            return $this->whereWordsMatch($base, $columns, $words, $allWords);
        }

        $attachmentIds = $this->attachmentOwnerIds($morph, $base, $words, $allWords);

        if (! $attachments->searchesOwnText()) {
            return $base->whereIn('id', $attachmentIds);
        }

        return $base->where(function (Builder $either) use ($attachmentIds, $columns, $words, $allWords) {
            $either->whereIn('id', $attachmentIds)
                ->orWhere(fn (Builder $own) => $this->whereWordsMatch($own, $columns, $words, $allWords));
        });
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
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchIssues(Collection $projects, ?User $viewer, array $words, bool $allWords, bool $titlesOnly, bool $openIssuesOnly, AttachmentSearchMode $attachments): Collection
    {
        $projectIds = $this->projectIdsPermitting($projects, $viewer, 'view_issues');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $matchedIds = collect();

        if ($attachments->searchesOwnText()) {
            $matchedIds = $this->whereWordsMatch(
                Issue::query()->whereIn('project_id', $projectIds),
                $titlesOnly ? ['subject'] : ['subject', 'description'],
                $words,
                $allWords,
            )->limit(self::RESULTS_PER_TYPE)->pluck('id');

            if (! $titlesOnly) {
                $matchedIds = $matchedIds->merge($this->issueIdsMatchingSearchableCustomFields($projectIds, $words, $allWords))->unique();
            }
        }

        if ($attachments->searchesAttachments($titlesOnly)) {
            $matchedIds = $matchedIds
                ->merge($this->attachmentOwnerIds('issue', Issue::query()->whereIn('project_id', $projectIds), $words, $allWords)->limit(self::RESULTS_PER_TYPE)->pluck('model_id'))
                ->unique();
        }

        // Word-matching above only narrows by project_id, not by whether
        // the viewer may actually see each matched issue (issues_visibility
        // Own/Default tiers, is_private) — applying visibleToAcrossProjects()
        // here, on the query that actually builds the returned results, is
        // enough to close that gap: any id a viewer shouldn't see is simply
        // dropped, regardless of which of the two searches above found it.
        return Issue::query()
            ->whereIn('id', $matchedIds)
            ->visibleToAcrossProjects($viewer, $projects->whereIn('id', $projectIds))
            ->when($openIssuesOnly, fn (Builder $query) => $query->whereHas('status', fn ($status) => $status->where('is_closed', false)))
            ->with('project')
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Issue $issue) => new SearchResult(
                type: 'issue',
                title: "#{$issue->id} {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                excerpt: $this->excerpt($issue->description),
                updatedAt: $issue->updated_at,
            ));
    }

    /**
     * @param  Collection<int, int>  $projectIds
     * @param  array<int, string>  $words
     * @return Collection<int, int>
     */
    private function issueIdsMatchingSearchableCustomFields(Collection $projectIds, array $words, bool $allWords): Collection
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
                ->whereIn('customized_id', fn ($q) => $q->select('id')->from('issues')->whereIn('project_id', $projectIds)),
            ['value_string', 'value_text'],
            $words,
            $allWords,
        )->pluck('customized_id');
    }

    /**
     * Narrows $projects to the ones $viewer holds $permission on, and
     * returns just their ids — every per-type search method needs this
     * same "which of the candidate projects may I search in for THIS
     * type" step before building its query.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, int>
     */
    private function projectIdsPermitting(Collection $projects, ?User $viewer, string $permission): Collection
    {
        return $projects
            ->filter(fn (Project $project) => $this->authorization->can($viewer, $permission, $project))
            ->pluck('id');
    }

    /**
     * The wiki page body lives on a related model (currentVersion), so
     * the per-word clause is built by hand instead of via
     * whereWordsMatch(): each word matches the title or, outside
     * titles-only mode, the current version's text.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchWikiPages(Collection $projects, ?User $viewer, array $words, bool $allWords, bool $titlesOnly, AttachmentSearchMode $attachments): Collection
    {
        $projectIds = $this->projectIdsPermitting($projects, $viewer, 'view_wiki_pages');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $pages = WikiPage::query()->whereIn('project_id', $projectIds);
        $attachmentIds = $attachments->searchesAttachments($titlesOnly)
            ? $this->attachmentOwnerIds('wiki_page', $pages, $words, $allWords)
            : null;

        return $pages
            ->where(function (Builder $either) use ($words, $allWords, $titlesOnly, $attachments, $attachmentIds) {
                if ($attachmentIds !== null) {
                    $either->whereIn('id', $attachmentIds);
                }

                if (! $attachments->searchesOwnText()) {
                    return;
                }

                $either->orWhere(function (Builder $outer) use ($words, $allWords, $titlesOnly) {
                    foreach ($words as $wordIndex => $word) {
                        $outer->where(function (Builder $inner) use ($word, $titlesOnly) {
                            $inner->where('title', 'like', "%{$word}%");

                            if (! $titlesOnly) {
                                $inner->orWhereHas('currentVersion', fn ($version) => $version->where('text', 'like', "%{$word}%"));
                            }
                        }, boolean: ($allWords || $wordIndex === 0) ? 'and' : 'or');
                    }
                });
            })
            ->with(['project', 'currentVersion'])
            ->limit(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (WikiPage $page) => new SearchResult(
                type: 'wiki-page',
                title: $page->title,
                url: route('wiki.show', [$page->project, $page]),
                excerpt: $this->excerpt($page->currentVersion?->text),
                updatedAt: $page->currentVersion->created_at,
            ));
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchNews(Collection $projects, ?User $viewer, array $words, bool $allWords, bool $titlesOnly, AttachmentSearchMode $attachments): Collection
    {
        $projectIds = $this->projectIdsPermitting($projects, $viewer, 'view_news');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return $this->whereMatchesOrHasMatchingAttachment(
            News::query()->whereIn('project_id', $projectIds),
            'news',
            $titlesOnly ? ['title'] : ['title', 'summary', 'description'],
            $words,
            $allWords,
            $titlesOnly,
            $attachments,
        )
            ->with('project')
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (News $news) => new SearchResult(
                type: 'news',
                title: $news->title,
                url: route('news.show', [$news->project, $news]),
                excerpt: $this->excerpt($news->description),
                updatedAt: $news->updated_at,
            ));
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchDocuments(Collection $projects, ?User $viewer, array $words, bool $allWords, bool $titlesOnly, AttachmentSearchMode $attachments): Collection
    {
        $projectIds = $this->projectIdsPermitting($projects, $viewer, 'view_documents');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return $this->whereMatchesOrHasMatchingAttachment(
            Document::query()->whereIn('project_id', $projectIds),
            'document',
            $titlesOnly ? ['title'] : ['title', 'description'],
            $words,
            $allWords,
            $titlesOnly,
            $attachments,
        )
            ->with('project')
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Document $document) => new SearchResult(
                type: 'document',
                title: $document->title,
                url: route('documents.show', [$document->project, $document]),
                excerpt: $this->excerpt($document->description),
                updatedAt: $document->updated_at,
            ));
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchMessages(Collection $projects, ?User $viewer, array $words, bool $allWords, bool $titlesOnly, AttachmentSearchMode $attachments): Collection
    {
        $projectIds = $this->projectIdsPermitting($projects, $viewer, 'view_messages');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return $this->whereMatchesOrHasMatchingAttachment(
            Message::query()->whereHas('board', fn ($board) => $board->whereIn('project_id', $projectIds)),
            'message',
            $titlesOnly ? ['subject'] : ['subject', 'content'],
            $words,
            $allWords,
            $titlesOnly,
            $attachments,
        )
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->load(['board.project'])
            ->map(fn (Message $message) => new SearchResult(
                type: 'message',
                title: $message->isTopic() ? $message->subject : "RE: {$message->subject}",
                url: route('messages.show', [$message->board->project, $message->board, $message->isTopic() ? $message : $message->parent_id]),
                excerpt: $this->excerpt($message->content),
                updatedAt: $message->updated_at,
            ));
    }

    /**
     * Unlike every other type here, a changeset has only one searchable
     * column (its commit message) — matches Redmine's Changeset
     * `acts_as_searchable :columns => 'comments'`, a single-element
     * column list. $titlesOnly is deliberately not a parameter: Redmine's
     * acts_as_searchable implementation handles titles_only by taking
     * just the *first* configured column, which for a single-column type
     * is the same column either way, so the mode has no effect here and
     * doesn't need threading through.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchChangesets(Collection $projects, ?User $viewer, array $words, bool $allWords): Collection
    {
        $projectIds = $this->projectIdsPermitting($projects, $viewer, 'view_changesets');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return $this->whereWordsMatch(
            Changeset::query()->whereHas('repository', fn ($repository) => $repository->whereIn('project_id', $projectIds)),
            ['comments'],
            $words,
            $allWords,
        )
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->load('repository.project')
            ->map(fn (Changeset $changeset) => new SearchResult(
                type: 'changeset',
                title: $changeset->shortRevision(),
                url: route($changeset->repository->routeName('repository.show'), $changeset->repository->routeParameters(['changeset' => $changeset])),
                excerpt: $this->excerpt($changeset->comments),
                updatedAt: $changeset->committed_on,
            ));
    }

    /**
     * Unlike every other type here, a project's own record needs no
     * additional per-type permission check: $projects is already exactly
     * "every project the viewer may search in at all" (resolved by the
     * caller before search()/searchAcrossProjects() runs), and matches
     * Redmine's own Project `acts_as_searchable` declaration, which
     * likewise sets no :view_permission of its own beyond that.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<int, string>  $words
     * @return Collection<int, SearchResult>
     */
    private function searchProjects(Collection $projects, array $words, bool $allWords, bool $titlesOnly): Collection
    {
        if ($projects->isEmpty()) {
            return collect();
        }

        return $this->whereWordsMatch(
            Project::query()->whereIn('id', $projects->pluck('id')),
            $titlesOnly ? ['name'] : ['name', 'identifier', 'description'],
            $words,
            $allWords,
        )
            ->take(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Project $project) => new SearchResult(
                type: 'project',
                title: $project->name,
                url: route('projects.show', $project),
                excerpt: $this->excerpt($project->description),
                updatedAt: $project->updated_at,
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
