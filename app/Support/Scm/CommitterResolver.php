<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Models\Repository;
use App\Models\User;

/**
 * The user behind a changeset's committer string — Redmine's
 * Changeset#find_committer_user / Repository#find_committer_user.
 *
 * An explicit mapping (RepositoryCommitter, managed on the
 * repository.committers admin screen) is checked first, against the exact
 * raw committer string — the same one an admin would see on an unmatched
 * changeset, so what they type there is what matches here. Only when there
 * is no mapping does this fall back to the automatic heuristic: the SCM's
 * raw "Name <email>" string (Git) or a bare username (Subversion) — the
 * email when present, else the whole string, against email/login.
 */
final class CommitterResolver
{
    public static function resolve(Repository $repository, string $committer): ?User
    {
        if (trim($committer) === '') {
            return null;
        }

        $mapped = $repository->committers()->where('committer', $committer)->first()?->user;

        if ($mapped !== null) {
            return $mapped;
        }

        $email = preg_match('/<([^>]+)>/', $committer, $matches) === 1 ? $matches[1] : $committer;

        return User::query()->where('email', $email)->orWhere('login', $email)->first();
    }
}
