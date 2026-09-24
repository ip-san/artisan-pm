<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Enums\ScmCapability;
use DateTimeImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Bazaar (B'-01), after Redmine's lib/redmine/scm/adapters/bazaar_adapter.rb,
 * driven through Breezy (`brz`), the maintained Bazaar implementation the
 * Sail image installs. $path is a local branch under scm.repositories_root.
 * A changeset's revision is its mainline revno (as in Redmine), and "HEAD"
 * means the last revision (-1).
 *
 * Breezy only runs hooks that plugins register, so --no-plugins (and
 * --no-aliases, against a user alias rewriting a command) keeps the
 * commands read-only; BRZ_LOG keeps it from needing a writable home.
 */
final readonly class BazaarAdapter implements HasBranchesAndTags, ScmAdapter
{
    /**
     * @var array<int, string>
     */
    private const array GLOBAL_OPTIONS = ['--no-plugins', '--no-aliases'];

    public function __construct(
        private string $path,
        private ?string $logEncoding = null,
    ) {}

    public function isAvailable(): bool
    {
        return ScmCommand::succeeds(fn () => $this->brz(['revno', $this->path], 10));
    }

    public function supports(ScmCapability $capability): bool
    {
        return true;
    }

    public function log(?string $sinceRevision = null): array
    {
        $start = $sinceRevision !== null && ctype_digit($sinceRevision) ? ((int) $sinceRevision) + 1 : 1;

        // -n1: mainline revisions only — a merge's own revisions (dotted
        // revnos) belong to the merge commit, as in Redmine.
        $result = $this->brz(['log', '--long', '--verbose', '--forward', '-n1', '-r', "{$start}..", $this->path], 60);

        if (! $result->successful()) {
            return [];
        }

        return $this->parseLog(CodesetConverter::logToUtf8($result->output(), $this->logEncoding));
    }

    public function diff(string $revision, ?string $fromRevision = null, ?string $path = null): string
    {
        $target = $path !== null ? $this->target($path) : $this->path;

        $result = $fromRevision === null
            ? $this->brz(['diff', '-c', $this->revision($revision), $target], 30)
            : $this->brz(['diff', '-r', $this->revision($fromRevision).'..'.$this->revision($revision), $target], 30);

        // `brz diff` exits 1 when there are differences, 0 when there are none.
        return in_array($result->exitCode(), [0, 1], true) ? CodesetConverter::toUtf8($result->output()) : '';
    }

    public function tree(string $revision, string $path = ''): array
    {
        $path = trim($path, '/');
        $args = ['ls', '-r', $this->revision($revision), '-d', $this->path];

        if ($path !== '') {
            $args[] = $path;
        }

        $result = $this->brz($args, 15);

        if (! $result->successful()) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", CodesetConverter::toUtf8($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            $isDirectory = str_ends_with($line, '/');
            $entryPath = rtrim($line, '/@*');

            $entries[] = new ScmTreeEntry(name: basename($entryPath), path: $entryPath, isDirectory: $isDirectory);
        }

        return $entries;
    }

    public function fileContentAt(string $revision, string $path): string
    {
        $result = $this->brz(['cat', '-r', $this->revision($revision), $this->target($path)], 15);

        return $result->successful() ? $result->output() : '';
    }

    public function blame(string $revision, string $path): array
    {
        $result = $this->brz(['annotate', '--all', '--long', '-r', $this->revision($revision), $this->target($path)], 30);

        if (! $result->successful()) {
            return [];
        }

        $lines = explode("\n", $result->output());

        if (end($lines) === '') {
            array_pop($lines);
        }

        $entries = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\S+)\s+(.*?)\s+\d{8} \|(?: (.*))?$/', $line, $matches) === 1) {
                $entries[] = new ScmBlameLine(revision: $matches[1], author: $matches[2], content: $matches[3] ?? '');
            }
        }

        return $entries;
    }

    private function revision(string $revision): string
    {
        return $revision === 'HEAD' ? '-1' : $revision;
    }

    /**
     * A path inside the branch, addressed through the branch itself so it
     * works for branches without a working tree too.
     */
    private function target(string $path): string
    {
        return $this->path.'/'.ltrim($path, '/');
    }

    /**
     * A Bazaar repository here is a single branch.
     */
    public function branches(): array
    {
        return [];
    }

    public function tags(): array
    {
        $result = $this->brz(['tags', '-d', $this->path], 15);

        if (! $result->successful()) {
            return [];
        }

        $names = [];

        // "name   revno" per line; a tag whose revision is missing shows "?".
        foreach (explode("\n", CodesetConverter::toUtf8($result->output())) as $line) {
            $name = preg_replace('/\s+\S+$/', '', trim($line));

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function brz(array $args, int $timeout): ProcessResult
    {
        return ScmCommand::run('brz', fn () => Process::env(['BRZ_LOG' => '/dev/null'])
            ->timeout($timeout)
            ->run(['brz', ...self::GLOBAL_OPTIONS, ...$args]));
    }

    /**
     * Parses `brz log --long --verbose`: a dashed separator, "key: value"
     * headers, an indented message, then indented file lists under
     * "added:", "removed:", "modified:", "renamed:" (old => new) and
     * "kind changed:". Directories (trailing "/") are left out, and the
     * "*" (executable) / "@" (symlink) suffixes are stripped.
     *
     * @return array<int, ScmLogEntry>
     */
    private function parseLog(string $output): array
    {
        $entries = [];

        foreach (preg_split('/^-{60}\n/m', $output) ?: [] as $block) {
            $headers = [];
            $message = [];
            $files = [];
            $section = null;

            foreach (explode("\n", $block) as $line) {
                if (preg_match('/^(message|added|removed|modified|renamed|kind changed):$/', $line, $matches) === 1) {
                    $section = $matches[1];

                    continue;
                }

                if ($section === null && preg_match('/^([a-z ]+): (.*)$/', $line, $matches) === 1) {
                    $headers[$matches[1]] ??= $matches[2];

                    continue;
                }

                if ($section === 'message') {
                    $message[] = str_starts_with($line, '  ') ? substr($line, 2) : $line;

                    continue;
                }

                if ($section !== null && str_starts_with($line, '  ')) {
                    $change = $this->parseFileChange($section, substr($line, 2));

                    if ($change !== null) {
                        $files[] = $change;
                    }
                }
            }

            if (! isset($headers['revno'], $headers['timestamp'])) {
                continue;
            }

            $entries[] = new ScmLogEntry(
                revision: strtok($headers['revno'], ' '),
                committer: $headers['author'] ?? $headers['committer'] ?? '',
                committedOn: new DateTimeImmutable(preg_replace('/^[A-Za-z]{3} /', '', $headers['timestamp'])),
                message: trim(implode("\n", $message)),
                files: $files,
            );
        }

        return $entries;
    }

    private function parseFileChange(string $section, string $item): ?ScmFileChange
    {
        $fromPath = null;

        if ($section === 'renamed' && str_contains($item, ' => ')) {
            [$fromPath, $item] = explode(' => ', $item, 2);
            $fromPath = rtrim($fromPath, '*@');
        }

        if (str_ends_with($item, '/')) {
            return null;
        }

        $action = match ($section) {
            'added' => 'A',
            'removed' => 'D',
            'renamed' => 'R',
            default => 'M',
        };

        return new ScmFileChange(path: rtrim($item, '*@'), action: $action, fromPath: $fromPath);
    }
}
