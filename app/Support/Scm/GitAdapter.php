<?php

declare(strict_types=1);

namespace App\Support\Scm;

use DateTimeImmutable;
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

    public function __construct(
        private string $path,
    ) {}

    public function isAvailable(): bool
    {
        return Process::path($this->path)->timeout(10)->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful();
    }

    public function log(?string $sinceRevision = null): array
    {
        $format = self::RECORD_START.'%H'.self::FIELD_SEP.'%an <%ae>'.self::FIELD_SEP.'%aI'.self::FIELD_SEP.'%B'.self::RECORD_END;
        $range = $sinceRevision !== null ? "{$sinceRevision}..HEAD" : 'HEAD';

        $result = Process::path($this->path)->timeout(60)->run([
            'git', 'log', '--reverse', '--name-status', "--pretty=format:{$format}", $range,
        ]);

        if (! $result->successful()) {
            // An empty repository (no commits yet) or an unreachable range
            // both fail this command — either way, there's simply no log.
            return [];
        }

        return $this->parseLog($result->output());
    }

    public function diff(string $revision): string
    {
        $result = Process::path($this->path)->timeout(30)->run(['git', 'show', '--format=', $revision]);

        return $result->successful() ? $result->output() : '';
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
