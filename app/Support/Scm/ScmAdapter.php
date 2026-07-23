<?php

declare(strict_types=1);

namespace App\Support\Scm;

/**
 * Boundary between the app and a specific version-control tool. Every
 * implementation shells out to the underlying VCS binary via Laravel's
 * Process facade with array-form arguments (never string-interpolated),
 * so repository paths and revisions can't reach a shell.
 */
interface ScmAdapter
{
    public function isAvailable(): bool;

    /**
     * Commits after $sinceRevision (exclusive), oldest first. Pass null to
     * fetch the full history from the first commit.
     *
     * @return array<int, ScmLogEntry>
     */
    public function log(?string $sinceRevision = null): array;

    /**
     * Unified diff text: a single revision against its parent, or —
     * when $fromRevision is given — the full change between the two
     * revisions' snapshots. Pass $path to scope the diff to just that
     * file instead of the whole revision/range.
     */
    public function diff(string $revision, ?string $fromRevision = null, ?string $path = null): string;

    /**
     * Immediate children of $path (a directory) at $revision — not
     * recursive.
     *
     * @return array<int, ScmTreeEntry>
     */
    public function tree(string $revision, string $path = ''): array;

    /**
     * Raw content of the file at $path as of $revision.
     */
    public function fileContentAt(string $revision, string $path): string;

    /**
     * Per-line authorship of the file at $path as of $revision, one
     * entry per line in file order.
     *
     * @return array<int, ScmBlameLine>
     */
    public function blame(string $revision, string $path): array;
}
