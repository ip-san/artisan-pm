<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Enums\ScmCapability;
use DateTimeImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Mercurial (B'-01), after Redmine's lib/redmine/scm/adapters/mercurial_adapter.rb.
 * $path is a local repository under scm.repositories_root, like the other
 * local adapters. A changeset's revision is its full node hash (stable
 * across clones, unlike the local revision number), and "HEAD" means tip.
 *
 * Mercurial runs hooks and loads extensions from the repository's own
 * .hg/hgrc — for read commands too (`pre-log` hooks, arbitrary Python
 * extensions). HGRCSKIPREPO/HGRCPATH switch every config file off, the
 * counterpart of GitAdapter's SAFETY_FLAGS, and HGPLAIN keeps the output
 * format independent of user settings.
 */
final readonly class MercurialAdapter implements HasBranchesAndTags, ScmAdapter
{
    private const string FIELD_SEP = "\x1f";

    private const string LIST_SEP = "\x1e";

    private const string PAIR_SEP = "\x1d";

    private const string RECORD_START = "\x02";

    private const string RECORD_END = "\x03";

    /**
     * @var array<string, string>
     */
    private const array ENVIRONMENT = [
        'HGRCSKIPREPO' => '1',
        'HGRCPATH' => '',
        'HGPLAIN' => '1',
        'HGENCODING' => 'UTF-8',
    ];

    public function __construct(
        private string $path,
        private ?string $logEncoding = null,
    ) {}

    public function isAvailable(): bool
    {
        return ScmCommand::succeeds(fn () => $this->hg(['root'], 10));
    }

    public function supports(ScmCapability $capability): bool
    {
        return true;
    }

    public function log(?string $sinceRevision = null): array
    {
        // Local revision numbers grow in the order changesets entered this
        // repository, so "since:tip minus since" is everything newer.
        $range = $sinceRevision !== null && preg_match('/^[0-9a-f]{12,40}$/', $sinceRevision) === 1
            ? "{$sinceRevision}:tip and not {$sinceRevision}"
            : '0:tip';

        $template = self::RECORD_START
            .'{node}'.self::FIELD_SEP
            .'{author}'.self::FIELD_SEP
            .'{date|rfc3339date}'.self::FIELD_SEP
            .'{desc}'.self::FIELD_SEP
            .'{join(file_adds, "'.self::LIST_SEP.'")}'.self::FIELD_SEP
            .'{join(file_dels, "'.self::LIST_SEP.'")}'.self::FIELD_SEP
            .'{join(file_mods, "'.self::LIST_SEP.'")}'.self::FIELD_SEP
            .'{file_copies % "{name}'.self::PAIR_SEP.'{source}'.self::LIST_SEP.'"}'
            .self::RECORD_END;

        $result = $this->hg(['log', '-r', $range, '--template', $template], 60);

        if (! $result->successful()) {
            return [];
        }

        return $this->parseLog(CodesetConverter::logToUtf8($result->output(), $this->logEncoding));
    }

    public function diff(string $revision, ?string $fromRevision = null, ?string $path = null): string
    {
        $pathArgs = $path !== null ? ["path:{$path}"] : [];

        $result = $fromRevision === null
            ? $this->hg(['diff', '-c', $this->revision($revision), ...$pathArgs], 30)
            : $this->hg(['diff', '-r', $this->revision($fromRevision), '-r', $this->revision($revision), ...$pathArgs], 30);

        return $result->successful() ? CodesetConverter::toUtf8($result->output()) : '';
    }

    public function tree(string $revision, string $path = ''): array
    {
        $path = trim($path, '/');
        $result = $this->hg(['manifest', '-r', $this->revision($revision)], 15);

        if (! $result->successful()) {
            return [];
        }

        $prefix = $path === '' ? '' : "{$path}/";
        $entries = [];

        foreach (explode("\n", CodesetConverter::toUtf8($result->output())) as $file) {
            if ($file === '' || ! str_starts_with($file, $prefix)) {
                continue;
            }

            $rest = substr($file, strlen($prefix));
            $slash = strpos($rest, '/');
            $name = $slash === false ? $rest : substr($rest, 0, $slash);

            $entries[$name] ??= new ScmTreeEntry(name: $name, path: $prefix.$name, isDirectory: $slash !== false);
        }

        return array_values($entries);
    }

    public function fileContentAt(string $revision, string $path): string
    {
        $result = $this->hg(['cat', '-r', $this->revision($revision), "path:{$path}"], 15);

        return $result->successful() ? $result->output() : '';
    }

    public function blame(string $revision, string $path): array
    {
        $result = $this->hg(['annotate', '-r', $this->revision($revision), '-u', '-c', '-T', 'json', "path:{$path}"], 30);

        if (! $result->successful()) {
            return [];
        }

        $files = json_decode($result->output(), true);
        $entries = [];

        foreach ($files[0]['lines'] ?? [] as $line) {
            $entries[] = new ScmBlameLine(
                revision: (string) ($line['node'] ?? ''),
                author: (string) ($line['user'] ?? ''),
                content: rtrim((string) ($line['line'] ?? ''), "\r\n"),
            );
        }

        return $entries;
    }

    private function revision(string $revision): string
    {
        return $revision === 'HEAD' ? 'tip' : $revision;
    }

    public function branches(): array
    {
        return $this->names(['branches', '-T', '{branch}\n']);
    }

    /**
     * The tags other than Mercurial's moving "tip".
     */
    public function tags(): array
    {
        return array_values(array_filter($this->names(['tags', '-T', '{tag}\n']), fn (string $tag) => $tag !== 'tip'));
    }

    /**
     * @param  array<int, string>  $args
     * @return list<string>
     */
    private function names(array $args): array
    {
        $result = $this->hg($args, 15);

        if (! $result->successful()) {
            return [];
        }

        $names = array_values(array_filter(explode("\n", CodesetConverter::toUtf8($result->output())), fn (string $name) => $name !== ''));
        sort($names);

        return $names;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function hg(array $args, int $timeout): ProcessResult
    {
        return ScmCommand::run('hg', fn () => Process::env(self::ENVIRONMENT)
            ->timeout($timeout)
            ->run(['hg', '--noninteractive', '-R', $this->path, ...$args]));
    }

    /**
     * @return array<int, string>
     */
    private static function split(string $list): array
    {
        return array_values(array_filter(explode(self::LIST_SEP, $list), fn (string $item) => $item !== ''));
    }

    /**
     * @return array<int, ScmLogEntry>
     */
    private function parseLog(string $output): array
    {
        preg_match_all('/'.self::RECORD_START.'(.*?)'.self::RECORD_END.'/s', $output, $records);

        $entries = [];

        foreach ($records[1] as $record) {
            [$node, $author, $date, $message, $adds, $dels, $mods, $copies] = array_pad(explode(self::FIELD_SEP, $record), 8, '');

            $copiedFrom = [];

            foreach (self::split($copies) as $pair) {
                [$name, $source] = array_pad(explode(self::PAIR_SEP, $pair, 2), 2, '');
                $copiedFrom[$name] = $source;
            }

            $files = [];

            foreach (self::split($adds) as $file) {
                $files[] = new ScmFileChange(path: $file, action: 'A', fromPath: $copiedFrom[$file] ?? null);
            }

            foreach (self::split($mods) as $file) {
                $files[] = new ScmFileChange(path: $file, action: 'M');
            }

            foreach (self::split($dels) as $file) {
                $files[] = new ScmFileChange(path: $file, action: 'D');
            }

            $entries[] = new ScmLogEntry(
                revision: $node,
                committer: $author,
                committedOn: new DateTimeImmutable($date),
                message: trim($message),
                files: $files,
            );
        }

        return $entries;
    }
}
