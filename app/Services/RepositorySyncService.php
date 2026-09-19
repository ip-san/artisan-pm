<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Fetches new commits from a Repository's adapter and records them as
 * Changesets — resuming from last_synced_revision so a re-run only
 * processes what's landed since the previous sync, not the full history.
 *
 * Also honors a configurable list of fixing-keyword rules
 * (commit_fixing_keyword_rules setting: an array of {keywords, status_id, done_ratio, if_tracker_id}
 * pairs) in the commit message: whichever rule's keyword list contains the
 * SPECIFIC keyword used in that commit determines the target status for
 * the issues it references — matches Redmine's Changeset#fix_issue, which
 * likewise looks up `Setting.commit_update_keywords_array.detect` by the
 * matched keyword rather than applying one global target. If no rules are
 * configured at all, falls back to the classic default (fixes/fix/closes/
 * close → the first closed status). A rule may also set done_ratio and
 * be limited to one tracker (if_tracker_id), as Redmine's
 * commit_update_keywords allows; the first rule matching the keyword and
 * the issue's tracker wins.
 * Which `#id`s a commit links to is governed by commit_ref_keywords
 * (`*` = any, the default here) and commit_cross_project_ref.
 * An issue already closed is left alone, matching Redmine's own
 * `return if issue.closed?` guard, rather than only skipping issues
 * already at that exact target status. This only fires when the commit's
 * free-text committer field resolves to a
 * real User — first via an explicit RepositoryCommitter mapping (see
 * resolveCommitter(), managed on the repository.committers admin
 * screen), falling back to matching the committer's email/login
 * automatically. Unmatched commits still link the issue (via
 * extractIssueIds) but don't change its status, since there'd be no
 * real user to attribute the journal entry to.
 *
 * Separately, an `@Nh`-style token right after an issue reference (e.g.
 * `refs #123 @2h30m`) logs time against that issue, gated by the
 * commit_logtime_enabled setting — matches Redmine's Changeset#log_time.
 * Only a subset of Redmine's TIMELOG_RE token grammar is recognized
 * (`2h`/`2hours`, `2h30m`/`2hours30min`, `30m`/`30min`, `1:30`, `2` or
 * `2.5`/`2,5`/`2.5h` as decimal hours) — see parseHoursToken()'s own
 * docblock for exactly what's still unmatched.
 *
 * The committer field is attacker-controlled (anyone who can push a
 * commit can set `git config user.email` to any address), so a matched
 * actor is *not* trusted outright: a status transition or logged time
 * entry only applies if that actor genuinely holds the relevant
 * permission (edit_issues / log_time) on the issue's project. Without
 * this check, spoofing another real user's commit email would force
 * changes attributed to — and effectively authorized as — them.
 */
final class RepositorySyncService
{
    private const string DEFAULT_FIXING_KEYWORDS = 'fixes, fix, closes, close';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TimeEntryService $timeEntries,
    ) {}

    /**
     * @return int number of changesets created
     */
    public function sync(Repository $repository): int
    {
        $entries = $repository->adapter()->log($repository->last_synced_revision);

        foreach ($entries as $entry) {
            $changeset = $repository->changesets()->create([
                'revision' => $entry->revision,
                'committer' => $entry->committer,
                'committed_on' => $entry->committedOn,
                'comments' => $entry->message,
            ]);

            foreach ($entry->files as $file) {
                $changeset->files()->create([
                    'path' => $file->path,
                    'action' => $file->action,
                    'from_path' => $file->fromPath,
                ]);
            }

            $issueIds = $this->extractIssueIds($repository, $entry->message);

            if ($issueIds !== []) {
                $changeset->issues()->sync($issueIds);
            }

            $this->applyFixingKeywords($repository, $entry->message, $entry->committer);
            $this->applyLoggedTime($repository, $entry->message, $entry->committer, $entry->committedOn);

            $repository->update(['last_synced_revision' => $entry->revision]);
        }

        return count($entries);
    }

    /**
     * The issues a commit message references. With the `*` wildcard in
     * commit_ref_keywords (the default here) every `#id` counts; otherwise
     * only ids that follow one of the reference or fixing keywords
     * (Redmine's Changeset#scan_comment_for_issue_ids). Issues in other,
     * unrelated projects are skipped unless commit_cross_project_ref is on.
     *
     * @return array<int, int>
     */
    private function extractIssueIds(Repository $repository, string $message): array
    {
        $ids = [];

        if ($this->referenceKeywords()['any']) {
            preg_match_all('/#(\d+)/', $message, $matches);
            $ids = $matches[1];
        } else {
            $keywords = [...$this->referenceKeywords()['keywords'], ...$this->fixingKeywords()];

            if ($keywords !== []) {
                $pattern = implode('|', array_map(preg_quote(...), $keywords));

                preg_match_all('/(?:^|[\s(\[,-])(?:'.$pattern.')[\s:]+(#\d+(?:[\s,;&]+#\d+)*)/i', $message, $lists);

                foreach ($lists[1] as $list) {
                    preg_match_all('/#(\d+)/', $list, $matches);
                    array_push($ids, ...$matches[1]);
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return $this->referenceableIssueIds($repository, array_map('intval', array_unique($ids)));
    }

    /**
     * @return array{any: bool, keywords: array<int, string>}
     */
    private function referenceKeywords(): array
    {
        $keywords = $this->splitKeywords((string) Setting::get('commit_ref_keywords', '*'));

        return ['any' => in_array('*', $keywords, true), 'keywords' => array_values(array_diff($keywords, ['*']))];
    }

    /**
     * Narrows issue ids to the ones a commit may reference: any existing
     * issue when commit_cross_project_ref is on (the default here), else
     * only issues of the repository's project, its ancestors or its
     * descendants — Redmine's Changeset#find_referenced_issue_by_id.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function referenceableIssueIds(Repository $repository, array $ids): array
    {
        $query = Issue::query()->whereIn('id', $ids);

        if (! (bool) Setting::get('commit_cross_project_ref', true)) {
            $project = $repository->project;

            $query->whereHas('project', fn ($related) => $related->where(fn ($either) => $either
                ->where(fn ($inside) => $inside->where('_lft', '>=', $project->_lft)->where('_rgt', '<=', $project->_rgt))
                ->orWhere(fn ($above) => $above->where('_lft', '<=', $project->_lft)->where('_rgt', '>=', $project->_rgt))));
        }

        return $query->pluck('id')->all();
    }

    private function applyFixingKeywords(Repository $repository, string $message, string $committer): void
    {
        $matches = $this->matchFixingKeywords($repository, $message);

        if ($matches === []) {
            return;
        }

        $actor = $this->resolveCommitter($repository, $committer);

        if ($actor === null) {
            return;
        }

        $issues = Issue::query()->with('status')->whereIn('id', array_column($matches, 'issueId'))->get()->keyBy('id');

        foreach ($matches as $match) {
            $issue = $issues->get($match['issueId']);

            if ($issue === null || $issue->status->is_closed) {
                continue;
            }

            $rule = $this->ruleFor($match['keyword'], $issue);

            if ($rule === null || ! $this->authorization->can($actor, 'edit_issues', $issue->loadMissing('project')->project)) {
                continue;
            }

            $changes = array_filter([
                'status_id' => $rule['statusId'],
                'done_ratio' => $rule['doneRatio'],
            ], fn (?int $value) => $value !== null);

            app(IssueService::class)->update(
                $issue,
                $changes,
                $actor,
                'コミットメッセージのキーワードにより自動的にステータスが変更されました。',
            );
        }
    }

    private function applyLoggedTime(Repository $repository, string $message, string $committer, DateTimeImmutable $committedOn): void
    {
        if (Setting::get('commit_logtime_enabled', false) !== true) {
            return;
        }

        $entries = $this->extractLoggedTime($message);

        if ($entries === []) {
            return;
        }

        $actor = $this->resolveCommitter($repository, $committer);

        if ($actor === null) {
            return;
        }

        $activityId = $this->resolveLogTimeActivityId();

        if ($activityId === null) {
            return;
        }

        $referenceable = $this->referenceableIssueIds($repository, array_column($entries, 'issueId'));
        $issues = Issue::query()->whereIn('id', $referenceable)->get()->keyBy('id');

        foreach ($entries as $entry) {
            $issue = $issues->get($entry['issueId']);

            if ($issue === null || ! $this->authorization->can($actor, 'log_time', $issue->project)) {
                continue;
            }

            // A `timelog_*` setting may reject the entry (closed issue, daily
            // cap, ...); the commit still syncs, only the time is skipped —
            // Redmine ignores the failed save the same way.
            try {
                $this->timeEntries->create([
                    'project_id' => $issue->project_id,
                    'issue_id' => $issue->id,
                    'user_id' => $actor->id,
                    'activity_id' => $activityId,
                    'hours' => $entry['hours'],
                    'spent_on' => $committedOn->format('Y-m-d'),
                    'comments' => 'コミットメッセージのキーワードにより自動的に記録されました。',
                ]);
            } catch (ValidationException) {
                continue;
            }
        }
    }

    /**
     * Alternation order matters here (unlike parseHoursToken()'s fully
     * ^anchored$ patterns, where backtracking makes order irrelevant):
     * this regex is unanchored, so PCRE accepts the first alternative
     * that matches *some* prefix — "hours?"/"min" must precede their
     * shorter "h"/"m" prefixes, or "2hours" would only ever capture "2h"
     * and silently strand "ours" in the message. That particular bug is
     * invisible to any test asserting only the final parsed hours value
     * (both captures parse to the same 2.0), which is why this is a
     * public, directly testable constant rather than an inline literal —
     * see the "captured in full" test in RepositorySyncTest.
     */
    public const string LOGGED_TIME_TOKEN_PATTERN = '/#(\d+)\s+@(\d+(?:hours?|h)\d+(?:min|m)?|\d+(?:hours?|h|min|m)|\d+:\d+|\d+(?:[.,]\d+)?h?)/i';

    /**
     * @return array<int, array{issueId: int, hours: float}>
     */
    private function extractLoggedTime(string $message): array
    {
        preg_match_all(self::LOGGED_TIME_TOKEN_PATTERN, $message, $matches, PREG_SET_ORDER);

        $entries = [];

        foreach ($matches as $match) {
            $hours = $this->parseHoursToken($match[2]);

            if ($hours !== null && $hours > 0) {
                $entries[] = ['issueId' => (int) $match[1], 'hours' => $hours];
            }
        }

        return $entries;
    }

    /**
     * Recognizes a subset of Redmine's TIMELOG_RE grammar: `2h`/`2hours`,
     * `2h30m`/`2hours30min`, `30m`/`30min`, `1:30` (hours:minutes), and a
     * bare `2`/`2.5`/`2,5` treated as decimal hours. Redmine's grammar
     * additionally accepts a bare trailing `h` on the decimal form
     * (`2.5h`) and singular/plural `hour`/`hours` — both covered here too
     * (verified against TIMELOG_RE in
     * /Users/sesoko/Desktop/workspace/redmine/app/models/changeset.rb).
     * Still not attempted: Redmine's `if_tracker_id`/`done_ratio` commit
     * options, which are a separate, unrelated grammar extension.
     */
    private function parseHoursToken(string $token): ?float
    {
        $token = str_replace(',', '.', $token);

        return match (true) {
            preg_match('/^(\d+)(?:h|hours?)(\d+)(?:m|min)?$/i', $token, $m) === 1 => (float) $m[1] + (float) $m[2] / 60,
            preg_match('/^(\d+)(?:h|hours?)$/i', $token, $m) === 1 => (float) $m[1],
            preg_match('/^(\d+)(?:m|min)$/i', $token, $m) === 1 => (float) $m[1] / 60,
            preg_match('/^(\d+):(\d+)$/', $token, $m) === 1 => (float) $m[1] + (float) $m[2] / 60,
            preg_match('/^(\d+(?:\.\d+)?)h?$/i', $token, $m) === 1 => (float) $m[1],
            default => null,
        };
    }

    /**
     * The setting-configured activity if it's still a valid TimeEntryActivity,
     * otherwise that type's default enumeration — mirrors Redmine's
     * Project#commit_logtime_activity falling through to TimeEntry's own
     * default activity resolution when unset.
     */
    private function resolveLogTimeActivityId(): ?int
    {
        $configuredId = Setting::get('commit_logtime_activity_id');

        if ($configuredId !== null) {
            $isValid = Enumeration::query()
                ->ofType(EnumerationType::TimeEntryActivity)
                ->where('id', $configuredId)
                ->exists();

            if ($isValid) {
                return (int) $configuredId;
            }
        }

        return Enumeration::query()
            ->ofType(EnumerationType::TimeEntryActivity)
            ->where('is_default', true)
            ->value('id');
    }

    /**
     * An explicit mapping (see RepositoryCommitter, managed on the
     * repository.committers admin screen) is checked first, against the
     * exact raw committer string — the same one an admin would see on an
     * unmatched changeset, so what they type there is what matches here.
     * Only when there's no mapping does this fall back to the automatic
     * heuristic: $committer is the SCM's raw "Name <email>" string (Git)
     * or a bare username (Subversion) — extracts the email when present,
     * falling back to matching the whole string against email/login
     * otherwise.
     */
    private function resolveCommitter(Repository $repository, string $committer): ?User
    {
        $mapped = $repository->committers()->where('committer', $committer)->first()?->user;

        if ($mapped !== null) {
            return $mapped;
        }

        $email = preg_match('/<([^>]+)>/', $committer, $matches) === 1 ? $matches[1] : $committer;

        return User::query()->where('email', $email)->orWhere('login', $email)->first();
    }

    /**
     * Every #id referenced right after a fixing keyword, with that keyword —
     * a later mention of the same issue id (possibly under another keyword)
     * overwrites the earlier one, so the last mention in the message wins.
     * Which rule then applies (and whether its tracker condition holds) is
     * decided per issue in ruleFor().
     *
     * @return array<int, array{issueId: int, keyword: string}>
     */
    private function matchFixingKeywords(Repository $repository, string $message): array
    {
        $keywords = $this->fixingKeywords();

        if ($keywords === []) {
            return [];
        }

        $pattern = implode('|', array_map(preg_quote(...), $keywords));
        preg_match_all('/\b('.$pattern.')\b\s+((?:#\d+[,\s]*)+)/i', $message, $matches, PREG_SET_ORDER);

        $results = [];

        foreach ($matches as $match) {
            preg_match_all('/#(\d+)/', $match[2], $idMatches);

            foreach ($idMatches[1] as $id) {
                $results[(int) $id] = ['issueId' => (int) $id, 'keyword' => mb_strtolower($match[1])];
            }
        }

        if ($results === []) {
            return [];
        }

        $validIds = $this->referenceableIssueIds($repository, array_keys($results));

        return array_values(array_intersect_key($results, array_flip($validIds)));
    }

    /**
     * @return array<int, string>
     */
    private function fixingKeywords(): array
    {
        return array_values(array_unique(array_merge(...array_column($this->fixingKeywordRules(), 'keywords') ?: [[]])));
    }

    /**
     * The first rule listing $keyword whose tracker condition (if any) fits
     * the issue — Redmine's `commit_update_keywords_array.detect`.
     *
     * @return array{keywords: array<int, string>, statusId: ?int, doneRatio: ?int, trackerId: ?int}|null
     */
    private function ruleFor(string $keyword, Issue $issue): ?array
    {
        foreach ($this->fixingKeywordRules() as $rule) {
            if (in_array($keyword, $rule['keywords'], true) && ($rule['trackerId'] === null || $rule['trackerId'] === $issue->tracker_id)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{keywords: array<int, string>, statusId: ?int, doneRatio: ?int, trackerId: ?int}>
     */
    private function fixingKeywordRules(): array
    {
        $configured = Setting::get('commit_fixing_keyword_rules');

        if ($configured === null) {
            $closedStatusId = IssueStatus::query()->where('is_closed', true)->orderBy('position')->value('id');

            if ($closedStatusId === null) {
                return [];
            }

            return [['keywords' => $this->splitKeywords(self::DEFAULT_FIXING_KEYWORDS), 'statusId' => $closedStatusId, 'doneRatio' => null, 'trackerId' => null]];
        }

        $rules = [];

        foreach ($configured as $rule) {
            $keywords = $this->splitKeywords((string) ($rule['keywords'] ?? ''));
            $statusId = filled($rule['status_id'] ?? null) ? (int) $rule['status_id'] : null;
            $doneRatio = filled($rule['done_ratio'] ?? null) ? (int) $rule['done_ratio'] : null;

            // A rule that changes nothing would only swallow its keywords.
            if ($keywords === [] || ($statusId === null && $doneRatio === null)) {
                continue;
            }

            $rules[] = [
                'keywords' => $keywords,
                'statusId' => $statusId,
                'doneRatio' => $doneRatio,
                'trackerId' => filled($rule['if_tracker_id'] ?? null) ? (int) $rule['if_tracker_id'] : null,
            ];
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    private function splitKeywords(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $keyword) => mb_strtolower(trim($keyword)),
            explode(',', $raw),
        ))));
    }
}
