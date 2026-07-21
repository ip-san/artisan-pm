<?php

declare(strict_types=1);

namespace App\Support\Scm;

use DateTimeImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final readonly class GitAdapter implements ScmAdapter
{
    /**
     * Field separator (US) between header parts, and record markers (STX/
     * ETX) bracketing each commit's header — chosen because none of them
     * can appear in a commit message, unlike a delimiter such as "|" or a
     * literal newline.
     */
    private const string FIELD_SEP = "\x1f";

    private const string RECORD_START = "\x02";

    private const string RECORD_END = "\x03";

    /**
     * -c overrides neutralizing config-based attack vectors a hostile
     * repository could plant in its own (committed or loose) .git/config —
     * fsmonitor/sshCommand/hooksPath can all be abused to run arbitrary
     * commands as soon as git reads config from the target directory, even
     * for otherwise read-only operations like log/show/ls-tree. This is
     * defense-in-depth on top of WithinRepositoriesRoot confining *which*
     * directories are reachable at all — it deliberately does NOT touch
     * safe.directory, so git's own dubious-ownership protection stays on.
     *
     * @var array<int, string>
     */
    private const array SAFETY_FLAGS = [
        '-c', 'protocol.ext.allow=never',
        '-c', 'core.fsmonitor=',
        '-c', 'core.hooksPath=/dev/null',
        '-c', 'core.sshCommand=false',
    ];

    public function __construct(
        private string $path,
    ) {}

    public function isAvailable(): bool
    {
        return $this->git(['rev-parse', '--is-inside-work-tree'], 10)->successful();
    }

    public function log(?string $sinceRevision = null): array
    {
        $format = self::RECORD_START.'%H'.self::FIELD_SEP.'%an <%ae>'.self::FIELD_SEP.'%aI'.self::FIELD_SEP.'%B'.self::RECORD_END;
        $range = $sinceRevision !== null ? "{$sinceRevision}..HEAD" : 'HEAD';

        $result = $this->git(['log', '--reverse', '--name-status', "--pretty=format:{$format}", $range], 60);

        if (! $result->successful()) {
            // An empty repository (no commits yet) or an unreachable range
            // both fail this command — either way, there's simply no log.
            return [];
        }

        return $this->parseLog($result->output());
    }

    public function diff(string $revision): string
    {
        $result = $this->git(['show', '--format=', $revision], 30);

        return $result->successful() ? $result->output() : '';
    }

    public function tree(string $revision, string $path = ''): array
    {
        $path = trim($path, '/');
        $treeish = $path === '' ? "{$revision}:" : "{$revision}:{$path}";

        $result = $this->git(['ls-tree', $treeish], 15);

        if (! $result->successful()) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$info, $name] = explode("\t", $line, 2);
            [, $type] = explode(' ', $info);

            $entries[] = new ScmTreeEntry(
                name: $name,
                path: $path === '' ? $name : "{$path}/{$name}",
                isDirectory: $type === 'tree',
            );
        }

        return $entries;
    }

    public function fileContentAt(string $revision, string $path): string
    {
        $result = $this->git(['show', "{$revision}:{$path}"], 15);

        return $result->successful() ? $result->output() : '';
    }

    /**
     * @param  array<int, string>  $args
     */
    private function git(array $args, int $timeout): ProcessResult
    {
        return Process::path($this->path)->timeout($timeout)->run(['git', ...self::SAFETY_FLAGS, ...$args]);
    }

    /**
     * @return array<int, ScmLogEntry>
     */
    private function parseLog(string $output): array
    {
        $entries = [];

        foreach (array_filter(explode(self::RECORD_START, $output)) as $chunk) {
            [$header, $filesBlock] = array_pad(explode(self::RECORD_END, $chunk, 2), 2, '');
            [$revision, $committer, $date, $message] = array_pad(explode(self::FIELD_SEP, $header, 4), 4, '');

            if ($revision === '') {
                continue;
            }

            $entries[] = new ScmLogEntry(
                revision: $revision,
                committer: $committer,
                committedOn: new DateTimeImmutable($date),
                message: trim($message),
                files: $this->parseFiles($filesBlock),
            );
        }

        return $entries;
    }

    /**
     * @return array<int, ScmFileChange>
     */
    private function parseFiles(string $filesBlock): array
    {
        $files = [];

        foreach (explode("\n", trim($filesBlock)) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);
            $action = substr($parts[0], 0, 1);
            $path = $parts[count($parts) - 1];

            $files[] = new ScmFileChange(path: $path, action: $action);
        }

        return $files;
    }
}
