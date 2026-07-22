<?php

declare(strict_types=1);

namespace App\Support\Scm;

use DateTimeImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * $path is a local filesystem path (same convention as GitAdapter, and
 * subject to the same WithinRepositoriesRoot containment), addressed
 * internally as a file:// URL — a bare path only works with the svn CLI
 * for an existing working copy, not for a raw repository created with
 * svnadmin create, which is what a Repository actually points at.
 *
 * Unlike GitAdapter, this doesn't add config-hardening flags: Subversion's
 * hook scripts (the closest equivalent to git's config-triggered command
 * execution) only run for repository-modifying operations, not the
 * read-only log/cat/list/diff commands used here, so there's no known
 * read-path attack surface to neutralize the way there is for git.
 */
final readonly class SvnAdapter implements ScmAdapter
{
    public function __construct(
        private string $path,
    ) {}

    public function isAvailable(): bool
    {
        return $this->svn(['info', $this->url()], 10)->successful();
    }

    public function log(?string $sinceRevision = null): array
    {
        // Ascending (oldest-first) requires the range written low:high —
        // svn has no separate --reverse flag for log the way git does.
        $start = $sinceRevision !== null ? ((int) $sinceRevision) + 1 : 1;

        $result = $this->svn(['log', '--xml', '--verbose', '-r', "{$start}:HEAD", $this->url()], 60);

        if (! $result->successful()) {
            return [];
        }

        return $this->parseLog($result->output());
    }

    public function diff(string $revision): string
    {
        $result = $this->svn(['diff', '-c', $revision, $this->url()], 30);

        return $result->successful() ? $result->output() : '';
    }

    public function tree(string $revision, string $path = ''): array
    {
        $path = trim($path, '/');
        $target = $path === '' ? $this->url() : "{$this->url()}/{$path}";

        $result = $this->svn(['list', '--xml', "{$target}@{$revision}"], 15);

        if (! $result->successful()) {
            return [];
        }

        return $this->parseTree($result->output(), $path);
    }

    public function fileContentAt(string $revision, string $path): string
    {
        $result = $this->svn(['cat', "{$this->url()}/{$path}@{$revision}"], 15);

        return $result->successful() ? $result->output() : '';
    }

    /**
     * `svn blame --xml` deliberately omits line content (it's meant to
     * pair with a separate `cat`, unlike git's --line-porcelain which
     * inlines everything) — this fetches the per-line revision/author
     * list and the file content as of the same revision separately,
     * then zips them together by position.
     */
    public function blame(string $revision, string $path): array
    {
        $result = $this->svn(['blame', '--xml', "{$this->url()}/{$path}@{$revision}"], 30);

        if (! $result->successful()) {
            return [];
        }

        $authorship = $this->parseBlameXml($result->output());
        $content = $this->fileContentAt($revision, $path);
        $lines = explode("\n", $content);

        if (str_ends_with($content, "\n")) {
            array_pop($lines);
        }

        $entries = [];

        foreach ($authorship as $i => [$lineRevision, $author]) {
            $entries[] = new ScmBlameLine(revision: $lineRevision, author: $author, content: $lines[$i] ?? '');
        }

        return $entries;
    }

    private function url(): string
    {
        return 'file://'.$this->path;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function svn(array $args, int $timeout): ProcessResult
    {
        return Process::timeout($timeout)->run(['svn', '--non-interactive', '--trust-server-cert', ...$args]);
    }

    /**
     * @return array<int, ScmLogEntry>
     */
    private function parseLog(string $xml): array
    {
        $document = @simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $entries = [];

        foreach ($document->logentry as $logEntry) {
            $files = [];

            foreach ($logEntry->paths->path ?? [] as $path) {
                $files[] = new ScmFileChange(
                    path: ltrim((string) $path, '/'),
                    action: (string) $path['action'],
                );
            }

            $entries[] = new ScmLogEntry(
                revision: (string) $logEntry['revision'],
                committer: (string) $logEntry->author,
                committedOn: new DateTimeImmutable((string) $logEntry->date),
                message: trim((string) $logEntry->msg),
                files: $files,
            );
        }

        return $entries;
    }

    /**
     * @return array<int, ScmTreeEntry>
     */
    private function parseTree(string $xml, string $path): array
    {
        $document = @simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $entries = [];

        foreach ($document->list->entry as $entry) {
            $name = (string) $entry->name;

            $entries[] = new ScmTreeEntry(
                name: $name,
                path: $path === '' ? $name : "{$path}/{$name}",
                isDirectory: (string) $entry['kind'] === 'dir',
            );
        }

        return $entries;
    }

    /**
     * @return array<int, array{0: string, 1: string}> revision/author pairs, one per line
     */
    private function parseBlameXml(string $xml): array
    {
        $document = @simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $entries = [];

        foreach ($document->target->entry as $entry) {
            $entries[] = [
                (string) ($entry->commit['revision'] ?? ''),
                (string) ($entry->commit->author ?? ''),
            ];
        }

        return $entries;
    }
}
