<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a ScmAdapter can do beyond listing a directory and reading a file,
 * which every adapter supports (B'-03). A Filesystem repository — Redmine's
 * Repository::Filesystem, which has no revisions — supports none of these,
 * so the pages built on them are hidden and their routes answer 404.
 */
enum ScmCapability: string
{
    /** Revision history: the changeset list, sync, stats, committers, file history. */
    case Log = 'log';

    /** Diffs between revisions: the changeset diff and compare pages. */
    case Diff = 'diff';

    /** Per-line authorship (annotate). */
    case Blame = 'blame';
}
