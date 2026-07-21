<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Issue;
use App\Models\Repository;

/**
 * Fetches new commits from a Repository's adapter and records them as
 * Changesets — resuming from last_synced_revision so a re-run only
 * processes what's landed since the previous sync, not the full history.
 */
final class RepositorySyncService
{
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
}
