<?php

declare(strict_types=1);

namespace App\Support\Scm;

use DateTimeImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use SensitiveParameter;

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
 *
 * A10-01b: alternatively $url names a remote svn://, http(s):// repository
 * on an allow-listed host (config('scm.allowed_hosts')), with optional
 * credentials. The host is re-checked by RemoteRepositoryUrlGuard before
 * every svn call, the password reaches svn over stdin
 * (--password-from-stdin) so it never appears in the process list, and
 * --no-auth-cache keeps svn from writing it to ~/.subversion.
 * --trust-server-cert is no longer passed: accepting an unknown CA would
 * hand the credentials to anyone able to intercept the connection.
 */
final readonly class SvnAdapter implements ScmAdapter
{
    public function __construct(
        private string $path = '',
        private ?string $url = null,
        private ?string $login = null,
        #[SensitiveParameter]
        private ?string $password = null,
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

        return $this->parseLog($result->output(), $this->relativeUrlPrefix());
    }

    /**
     * `svn log` reports changed paths relative to the repository root, not
     * to the URL — a remote URL may point below the root (".../trunk"), so,
     * as Redmine's Repository::Subversion#relative_path does, that prefix
     * is stripped to match the paths tree()/fileContentAt() use. A local
     * repository is always addressed at its root, so this is remote-only.
     */
    private function relativeUrlPrefix(): string
    {
        if ($this->url === null) {
            return '';
        }

        $result = $this->svn(['info', '--show-item', 'relative-url', $this->url()], 15);
        $relative = $result->successful() ? trim(ltrim(trim($result->output()), '^'), '/') : '';

        return $relative === '' ? '' : rawurldecode($relative).'/';
    }

    public function diff(string $revision, ?string $fromRevision = null, ?string $path = null): string
    {
        $target = $path !== null ? "{$this->url()}/{$path}" : $this->url();

        $result = $fromRevision === null
            ? $this->svn(['diff', '-c', $revision, $target], 30)
            : $this->svn(['diff', '-r', "{$fromRevision}:{$revision}", $target], 30);

        return $result->successful() ? CodesetConverter::toUtf8($result->output()) : '';
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
        return $this->url !== null ? rtrim($this->url, '/') : 'file://'.$this->path;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function svn(array $args, int $timeout): ProcessResult
    {
        $command = ['svn', '--non-interactive', '--no-auth-cache'];
        $process = Process::timeout($timeout);

        if ($this->url !== null) {
            $problem = RemoteRepositoryUrlGuard::problem($this->url);

            if ($problem !== null) {
                Log::warning('Refused to contact a remote Subversion repository.', ['url' => $this->url, 'reason' => $problem]);

                return new FakeProcessResult(command: 'svn', exitCode: 1, errorOutput: $problem);
            }

            if (filled($this->login)) {
                $command = [...$command, '--username', (string) $this->login];

                if (filled($this->password)) {
                    $command[] = '--password-from-stdin';
                    $process = $process->input((string) $this->password);
                }
            }
        }

        return $process->run([...$command, ...$args]);
    }

    /**
     * @return array<int, ScmLogEntry>
     */
    private function parseLog(string $xml, string $prefix = ''): array
    {
        $relative = fn (string $path): string => $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;

        $document = @simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $entries = [];

        foreach ($document->logentry as $logEntry) {
            $files = [];

            foreach ($logEntry->paths->path ?? [] as $path) {
                // SVN represents a rename as a delete of the old path plus
                // an add of the new path carrying copyfrom-path/-rev — the
                // add side is what links the two together.
                $copyFromPath = (string) ($path['copyfrom-path'] ?? '');

                $files[] = new ScmFileChange(
                    path: $relative(ltrim((string) $path, '/')),
                    action: (string) $path['action'],
                    fromPath: $copyFromPath !== '' ? $relative(ltrim($copyFromPath, '/')) : null,
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
