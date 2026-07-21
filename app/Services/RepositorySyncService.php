<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Repository;
use App\Models\User;

/**
 * Fetches new commits from a Repository's adapter and records them as
 * Changesets — resuming from last_synced_revision so a re-run only
 * processes what's landed since the previous sync, not the full history.
 *
 * Also honors a small set of fixing keywords (fixes/fix/closes/close) in
 * the commit message: any issue they reference gets transitioned to the
 * first closed status, same as Redmine's default keyword behavior. This
 * only fires when the commit's free-text committer field happens to match
 * a real User's email or login exactly — there's no dedicated committer-
 * to-user mapping UI yet (a separate, larger gap), so unmatched commits
 * still link the issue (via extractIssueIds) but don't change its status,
 * since there'd be no real user to attribute the journal entry to.
 * Deliberately skips any edit_issues permission check on the matched
 * user — Redmine does check it, but replicating that here would require
 * a project in scope this method doesn't otherwise need.
 */
final class RepositorySyncService
{
    /**
     * @var array<int, string>
     */
    private const array FIXING_KEYWORDS = ['fixes', 'fix', 'closes', 'close'];

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

            $this->applyFixingKeywords($entry->message, $entry->committer);

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

    private function applyFixingKeywords(string $message, string $committer): void
    {
        $fixedIds = $this->extractFixedIssueIds($message);

        if ($fixedIds === []) {
            return;
        }

        $actor = $this->resolveCommitter($committer);

        if ($actor === null) {
            return;
        }

        $closedStatusId = IssueStatus::query()->where('is_closed', true)->orderBy('position')->value('id');

        if ($closedStatusId === null) {
            return;
        }

        $issues = Issue::query()->whereIn('id', $fixedIds)->where('status_id', '!=', $closedStatusId)->get();

        foreach ($issues as $issue) {
            app(IssueService::class)->update(
                $issue,
                ['status_id' => $closedStatusId],
                $actor,
                'コミットメッセージのキーワードにより自動的にクローズされました。',
            );
        }
    }

    /**
     * $committer is the SCM's raw "Name <email>" string (Git) or a bare
     * username (Subversion) — extracts the email when present, falling
     * back to matching the whole string against email/login otherwise.
     */
    private function resolveCommitter(string $committer): ?User
    {
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
