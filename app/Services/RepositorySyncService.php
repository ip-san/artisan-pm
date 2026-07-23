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

/**
 * Fetches new commits from a Repository's adapter and records them as
 * Changesets — resuming from last_synced_revision so a re-run only
 * processes what's landed since the previous sync, not the full history.
 *
 * Also honors a small set of fixing keywords (fixes/fix/closes/close) in
 * the commit message: any issue they reference gets transitioned to the
 * first closed status, same as Redmine's default keyword behavior. This
 * only fires when the commit's free-text committer field resolves to a
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
 * (`2h`, `2h30m`, `30m`, `1:30`, `2` or `2.5`/`2,5` as bare decimal
 * hours) — an intentional simplification, not full grammar parity.
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
    /**
     * @var array<int, string>
     */
    private const array FIXING_KEYWORDS = ['fixes', 'fix', 'closes', 'close'];

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
                ]);
            }

            $issueIds = $this->extractIssueIds($entry->message);

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
     * @return array<int, int>
     */
    private function extractIssueIds(string $message): array
    {
        preg_match_all('/#(\d+)/', $message, $matches);

        if ($matches[1] === []) {
            return [];
        }

        return Issue::query()->whereIn('id', array_unique($matches[1]))->pluck('id')->all();
    }

    private function applyFixingKeywords(Repository $repository, string $message, string $committer): void
    {
        $fixedIds = $this->extractFixedIssueIds($message);

        if ($fixedIds === []) {
            return;
        }

        $actor = $this->resolveCommitter($repository, $committer);

        if ($actor === null) {
            return;
        }

        $closedStatusId = IssueStatus::query()->where('is_closed', true)->orderBy('position')->value('id');

        if ($closedStatusId === null) {
            return;
        }

        $issues = Issue::query()->whereIn('id', $fixedIds)->where('status_id', '!=', $closedStatusId)->get();

        foreach ($issues as $issue) {
            if (! $this->authorization->can($actor, 'edit_issues', $issue->project)) {
                continue;
            }

            app(IssueService::class)->update(
                $issue,
                ['status_id' => $closedStatusId],
                $actor,
                'コミットメッセージのキーワードにより自動的にクローズされました。',
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

        $issues = Issue::query()->whereIn('id', array_column($entries, 'issueId'))->get()->keyBy('id');

        foreach ($entries as $entry) {
            $issue = $issues->get($entry['issueId']);

            if ($issue === null || ! $this->authorization->can($actor, 'log_time', $issue->project)) {
                continue;
            }

            $this->timeEntries->create([
                'project_id' => $issue->project_id,
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'activity_id' => $activityId,
                'hours' => $entry['hours'],
                'spent_on' => $committedOn->format('Y-m-d'),
                'comments' => 'コミットメッセージのキーワードにより自動的に記録されました。',
            ]);
        }
    }

    /**
     * @return array<int, array{issueId: int, hours: float}>
     */
    private function extractLoggedTime(string $message): array
    {
        preg_match_all('/#(\d+)\s+@(\d+h\d+m?|\d+h|\d+m|\d+:\d+|\d+(?:[.,]\d+)?)/i', $message, $matches, PREG_SET_ORDER);

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
     * Recognizes a subset of Redmine's TIMELOG_RE grammar: `2h`, `2h30m`,
     * `30m`, `1:30` (hours:minutes), and a bare `2`/`2.5`/`2,5` treated as
     * decimal hours.
     */
    private function parseHoursToken(string $token): ?float
    {
        $token = str_replace(',', '.', $token);

        return match (true) {
            preg_match('/^(\d+)h(\d+)m?$/i', $token, $m) === 1 => (float) $m[1] + (float) $m[2] / 60,
            preg_match('/^(\d+)h$/i', $token, $m) === 1 => (float) $m[1],
            preg_match('/^(\d+)m$/i', $token, $m) === 1 => (float) $m[1] / 60,
            preg_match('/^(\d+):(\d+)$/', $token, $m) === 1 => (float) $m[1] + (float) $m[2] / 60,
            preg_match('/^\d+(?:\.\d+)?$/', $token) === 1 => (float) $token,
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
     * @return array<int, int>
     */
    private function extractFixedIssueIds(string $message): array
    {
        $keywords = implode('|', self::FIXING_KEYWORDS);
        preg_match_all('/\b(?:'.$keywords.')\b\s+((?:#\d+[,\s]*)+)/i', $message, $matches);

        $ids = [];

        foreach ($matches[1] as $group) {
            preg_match_all('/#(\d+)/', $group, $idMatches);
            $ids = array_merge($ids, $idMatches[1]);
        }

        if ($ids === []) {
            return [];
        }

        return Issue::query()->whereIn('id', array_unique($ids))->pluck('id')->all();
    }
}
