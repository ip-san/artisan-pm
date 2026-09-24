<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Enums\ScmCapability;
use DateTimeImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * CVS (B'-01), after Redmine's lib/redmine/scm/adapters/cvs_adapter.rb and
 * Repository::Cvs#fetch_changesets.
 *
 * $path is a module directory inside a local CVS repository under
 * scm.repositories_root, e.g. ".../cvsroot/project": the CVSROOT is the
 * nearest ancestor holding a CVSROOT/ directory (not above
 * repositories_root), and the module is the rest of the path. Redmine asks
 * for the two separately; one path keeps this app's single path field.
 *
 * CVS versions files, not changesets. As in Redmine, trunk file revisions
 * are grouped into changesets — by commitid (CVS 1.12 records one per
 * commit), else by the same author and message within 10 seconds — and
 * numbered 1, 2, 3... in time order. The numbering is recomputed from the
 * full `rlog` every time (commit times only grow, so earlier numbers never
 * change); that also answers "which file revisions make up changeset N"
 * for diffs, trees and file contents, so every call runs one rlog first.
 * Vendor-branch and other branch revisions are not shown; an import's
 * "Initial revision" 1.1 takes the message of its vendor revision 1.1.1.1.
 *
 * Every command runs with -f (ignore ~/.cvsrc) and -R (read-only
 * repository: no locks, no history writes). Files are read with `co -p`,
 * which doesn't run a module's CVSROOT/modules "-o" program.
 */
final readonly class CvsAdapter implements ScmAdapter
{
    /**
     * Redmine's time_delta for grouping file revisions without a commitid.
     */
    private const int GROUPING_WINDOW_SECONDS = 10;

    private const string FILE_SEPARATOR = "=============================================================================\n";

    private const string REVISION_SEPARATOR = "----------------------------\n";

    public function __construct(
        private string $path,
        private ?string $logEncoding = null,
    ) {}

    public function isAvailable(): bool
    {
        $location = $this->location();

        return $location !== null && ScmCommand::succeeds(fn () => $this->cvs($location['root'], ['rlog', '-h', $location['module']], 15));
    }

    public function supports(ScmCapability $capability): bool
    {
        return true;
    }

    public function log(?string $sinceRevision = null): array
    {
        $since = $sinceRevision !== null && ctype_digit($sinceRevision) ? (int) $sinceRevision : 0;

        return array_map(
            fn (array $changeset) => $changeset['entry'],
            array_slice($this->changesets(), $since),
        );
    }

    public function diff(string $revision, ?string $fromRevision = null, ?string $path = null): string
    {
        $location = $this->location();
        $changesets = $this->changesets();
        $to = $this->changesetNumber($revision, $changesets);

        if ($location === null || $to === null) {
            return '';
        }

        $from = $fromRevision === null ? $to - 1 : $this->changesetNumber($fromRevision, $changesets);

        if ($from === null) {
            return '';
        }

        $before = $this->fileRevisionsAt($changesets, $from);
        $after = $this->fileRevisionsAt($changesets, $to);

        $paths = $fromRevision === null
            ? array_keys($changesets[$to - 1]['revisions'])
            : array_keys(array_filter(
                $before + $after,
                fn (string $file) => ($before[$file] ?? null) !== ($after[$file] ?? null),
                ARRAY_FILTER_USE_KEY,
            ));

        if ($path !== null) {
            $path = trim($path, '/');
            $paths = array_filter($paths, fn (string $file) => $file === $path || str_starts_with($file, "{$path}/"));
        }

        sort($paths);
        $diff = '';

        foreach ($paths as $file) {
            $diff .= $this->fileDiff($location, $file, $before[$file] ?? null, $after[$file] ?? null);
        }

        return CodesetConverter::toUtf8($diff);
    }

    public function tree(string $revision, string $path = ''): array
    {
        $changesets = $this->changesets();
        $number = $this->changesetNumber($revision, $changesets);

        if ($number === null) {
            return [];
        }

        $path = trim($path, '/');
        $prefix = $path === '' ? '' : "{$path}/";
        $entries = [];

        foreach (array_keys($this->fileRevisionsAt($changesets, $number)) as $file) {
            if (! str_starts_with($file, $prefix)) {
                continue;
            }

            $rest = substr($file, strlen($prefix));
            $slash = strpos($rest, '/');
            $name = $slash === false ? $rest : substr($rest, 0, $slash);

            $entries[$name] ??= new ScmTreeEntry(name: $name, path: $prefix.$name, isDirectory: $slash !== false);
        }

        ksort($entries);

        return array_values($entries);
    }

    public function fileContentAt(string $revision, string $path): string
    {
        $location = $this->location();
        $fileRevision = $this->fileRevision($revision, $path);

        if ($location === null || $fileRevision === null) {
            return '';
        }

        $result = $this->cvs($location['root'], ['co', '-p', '-r', $fileRevision, $this->modulePath($location, $path)], 15);

        return $result->successful() ? $result->output() : '';
    }

    /**
     * Per-line file revisions (e.g. "1.2"), as CVS itself annotates — not
     * the changeset numbers.
     */
    public function blame(string $revision, string $path): array
    {
        $location = $this->location();
        $fileRevision = $this->fileRevision($revision, $path);

        if ($location === null || $fileRevision === null) {
            return [];
        }

        $result = $this->cvs($location['root'], ['rannotate', '-r', $fileRevision, $this->modulePath($location, $path)], 30);

        if (! $result->successful()) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^([\d.]+)\s+\((.+?)\s+\d{2}-[A-Za-z]{3}-\d{2}\):(?: (.*))?$/', $line, $matches) === 1) {
                $entries[] = new ScmBlameLine(revision: $matches[1], author: $matches[2], content: $matches[3] ?? '');
            }
        }

        return $entries;
    }

    /**
     * @return ?array{root: string, module: string}
     */
    private function location(): ?array
    {
        $target = realpath($this->path);
        $boundary = realpath((string) config('scm.repositories_root'));

        if ($target === false || $boundary === false || ! is_dir($target)) {
            return null;
        }

        for ($directory = dirname($target); ; $directory = dirname($directory)) {
            if ($directory !== $boundary && ! str_starts_with($directory, $boundary.DIRECTORY_SEPARATOR)) {
                return null;
            }

            if (is_dir($directory.DIRECTORY_SEPARATOR.'CVSROOT')) {
                return ['root' => $directory, 'module' => substr($target, strlen($directory) + 1)];
            }

            if ($directory === $boundary) {
                return null;
            }
        }
    }

    /**
     * @param  array{root: string, module: string}  $location
     */
    private function modulePath(array $location, string $path): string
    {
        return $location['module'].'/'.trim($path, '/');
    }

    /**
     * @param  array<int, string>  $args
     */
    private function cvs(string $root, array $args, int $timeout): ProcessResult
    {
        return ScmCommand::run('cvs', fn () => Process::timeout($timeout)->run(['cvs', '-f', '-Q', '-R', '-d', $root, ...$args]));
    }

    /**
     * @param  array<int, array{entry: ScmLogEntry, revisions: array<string, ?string>}>  $changesets
     */
    private function changesetNumber(string $revision, array $changesets): ?int
    {
        if ($revision === 'HEAD') {
            return count($changesets);
        }

        if (! ctype_digit($revision) || (int) $revision < 0 || (int) $revision > count($changesets)) {
            return null;
        }

        return (int) $revision;
    }

    private function fileRevision(string $revision, string $path): ?string
    {
        $changesets = $this->changesets();
        $number = $this->changesetNumber($revision, $changesets);

        return $number === null ? null : ($this->fileRevisionsAt($changesets, $number)[trim($path, '/')] ?? null);
    }

    /**
     * Path => file revision of every file alive after changeset $number.
     *
     * @param  array<int, array{entry: ScmLogEntry, revisions: array<string, ?string>}>  $changesets
     * @return array<string, string>
     */
    private function fileRevisionsAt(array $changesets, int $number): array
    {
        $files = [];

        foreach (array_slice($changesets, 0, $number) as $changeset) {
            foreach ($changeset['revisions'] as $path => $revision) {
                if ($revision === null) {
                    unset($files[$path]);
                } else {
                    $files[$path] = $revision;
                }
            }
        }

        return $files;
    }

    /**
     * A unified diff of one file between two file revisions; `rdiff` for a
     * change, and built from the content for an added or removed file,
     * which `rdiff` only reports as "is new"/"is removed".
     *
     * @param  array{root: string, module: string}  $location
     */
    private function fileDiff(array $location, string $path, ?string $fromRevision, ?string $toRevision): string
    {
        $modulePath = $this->modulePath($location, $path);

        if ($fromRevision !== null && $toRevision !== null) {
            $result = $this->cvs($location['root'], ['rdiff', '-u', '-r', $fromRevision, '-r', $toRevision, $modulePath], 30);

            return $result->successful() ? $result->output() : '';
        }

        if ($fromRevision === null && $toRevision === null) {
            return '';
        }

        $content = $this->cvs($location['root'], ['co', '-p', '-r', (string) ($toRevision ?? $fromRevision), $modulePath], 15)->output();
        $lines = explode("\n", $content);

        if (str_ends_with($content, "\n")) {
            array_pop($lines);
        }

        $count = count($lines);
        $sign = $toRevision !== null ? '+' : '-';
        $header = $toRevision !== null
            ? "--- /dev/null\n+++ {$modulePath}:{$toRevision}\n@@ -0,0 +1,{$count} @@\n"
            : "--- {$modulePath}:{$fromRevision}\n+++ /dev/null\n@@ -1,{$count} +0,0 @@\n";

        return "Index: {$modulePath}\n{$header}".implode('', array_map(fn (string $line) => "{$sign}{$line}\n", $lines));
    }

    /**
     * Every changeset of the module, oldest first, numbered from 1, with
     * the CVS file revision (e.g. "1.2", null for a removal) each of its
     * files moved to.
     *
     * @return array<int, array{entry: ScmLogEntry, revisions: array<string, ?string>}>
     */
    private function changesets(): array
    {
        $location = $this->location();

        if ($location === null) {
            return [];
        }

        $result = $this->cvs($location['root'], ['rlog', $location['module']], 60);

        if (! $result->successful()) {
            return [];
        }

        $revisions = $this->parseRlog(CodesetConverter::logToUtf8($result->output(), $this->logEncoding), $location);

        usort($revisions, fn (array $a, array $b) => [$a['time'], $a['path']] <=> [$b['time'], $b['path']]);

        /** @var array<int, array{key: ?string, author: string, message: string, time: DateTimeImmutable, last: DateTimeImmutable, files: array<int, ScmFileChange>, revisions: array<string, ?string>}> $groups */
        $groups = [];
        $byCommitId = [];

        foreach ($revisions as $revision) {
            $index = null;

            if ($revision['commitid'] !== null) {
                $index = $byCommitId[$revision['commitid']] ?? null;
            } else {
                foreach ($groups as $candidate => $group) {
                    if ($group['key'] === null
                        && $group['author'] === $revision['author']
                        && $group['message'] === $revision['message']
                        && $revision['time']->getTimestamp() - $group['last']->getTimestamp() <= self::GROUPING_WINDOW_SECONDS) {
                        $index = $candidate;
                    }
                }
            }

            if ($index === null) {
                $index = count($groups);
                $groups[] = ['key' => $revision['commitid'], 'author' => $revision['author'], 'message' => $revision['message'], 'time' => $revision['time'], 'last' => $revision['time'], 'files' => [], 'revisions' => []];

                if ($revision['commitid'] !== null) {
                    $byCommitId[$revision['commitid']] = $index;
                }
            }

            $groups[$index]['last'] = max($groups[$index]['last'], $revision['time']);
            $groups[$index]['files'][] = new ScmFileChange(path: $revision['path'], action: $revision['action']);
            $groups[$index]['revisions'][$revision['path']] = $revision['action'] === 'D' ? null : $revision['revision'];
        }

        $changesets = [];

        foreach ($groups as $i => $group) {
            $changesets[] = [
                'entry' => new ScmLogEntry(
                    revision: (string) ($i + 1),
                    committer: $group['author'],
                    committedOn: $group['time'],
                    message: $group['message'],
                    files: $group['files'],
                ),
                'revisions' => $group['revisions'],
            ];
        }

        return $changesets;
    }

    /**
     * Trunk file revisions from `cvs rlog`, one row per file revision.
     *
     * @param  array{root: string, module: string}  $location
     * @return array<int, array{path: string, revision: string, time: DateTimeImmutable, author: string, action: string, commitid: ?string, message: string}>
     */
    private function parseRlog(string $output, array $location): array
    {
        $prefix = $location['root'].'/'.$location['module'].'/';
        $rows = [];

        foreach (explode(self::FILE_SEPARATOR, $output) as $fileBlock) {
            if (preg_match('/^RCS file: (.+),v$/m', $fileBlock, $matches) !== 1 || ! str_starts_with($matches[1], $prefix)) {
                continue;
            }

            $path = preg_replace('#(^|/)Attic/([^/]+)$#', '$1$2', substr($matches[1], strlen($prefix)));
            $chunks = explode(self::REVISION_SEPARATOR, $fileBlock);
            array_shift($chunks);

            $parsed = [];
            $vendorMessages = [];

            foreach ($chunks as $chunk) {
                $lines = explode("\n", rtrim($chunk, "\n"));

                if (preg_match('/^revision ([\d.]+)/', $lines[0] ?? '', $revisionMatch) !== 1
                    || preg_match('/^date: ([^;]+);\s+author: ([^;]+);\s+state: ([^;]+);(?:.*commitid: ([^;]+);)?/', $lines[1] ?? '', $dateMatch) !== 1) {
                    continue;
                }

                $messageLines = array_slice($lines, 2);

                if (str_starts_with($messageLines[0] ?? '', 'branches:')) {
                    array_shift($messageLines);
                }

                $message = trim(implode("\n", $messageLines));
                $commitId = ($dateMatch[4] ?? '') !== '' ? $dateMatch[4] : null;

                if ($revisionMatch[1] === '1.1.1.1') {
                    $vendorMessages[$commitId ?? ''] = $message;
                }

                $parsed[] = [
                    'revision' => $revisionMatch[1],
                    'time' => new DateTimeImmutable($dateMatch[1]),
                    'author' => $dateMatch[2],
                    'dead' => $dateMatch[3] === 'dead',
                    'commitid' => $commitId,
                    'message' => $message === '*** empty log message ***' ? '' : $message,
                ];
            }

            // rlog lists newest first; walk oldest first to know whether a
            // revision follows a removal (a re-add) or not.
            $previousDead = true;

            foreach (array_reverse($parsed) as $revision) {
                if (preg_match('/^\d+\.\d+$/', $revision['revision']) !== 1) {
                    continue;
                }

                if ($revision['dead'] && $previousDead) {
                    continue;
                }

                $message = $revision['message'];

                if ($revision['revision'] === '1.1' && $message === 'Initial revision' && isset($vendorMessages[$revision['commitid'] ?? ''])) {
                    $message = $vendorMessages[$revision['commitid'] ?? ''];
                }

                $rows[] = [
                    'path' => $path,
                    'revision' => $revision['revision'],
                    'time' => $revision['time'],
                    'author' => $revision['author'],
                    'action' => $revision['dead'] ? 'D' : ($previousDead ? 'A' : 'M'),
                    'commitid' => $revision['commitid'],
                    'message' => $message,
                ];

                $previousDead = $revision['dead'];
            }
        }

        return $rows;
    }
}
