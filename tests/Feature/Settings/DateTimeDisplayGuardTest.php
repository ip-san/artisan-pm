<?php

/**
 * A4-12: stored times are UTC and must reach the screen through
 * App\Support\Format\DateTimes, which turns them into the viewer's zone
 * (and, A4-12c, the configured format). Formatting a timestamp attribute
 * directly in a view shows UTC to everyone, so these patterns fail here.
 *
 * Allowed: machine-readable output that stays UTC on purpose. Keyed by
 * file path (relative to the project) => the exact matched snippets.
 */
const DATE_TIME_DISPLAY_ALLOWED = [
    // Atom's <updated> is RFC 3339 with its offset, read by feed readers.
    'resources/views/feeds/atom.blade.php' => [
        '->occurredAt ?? now())->toAtomString(',
        '$entry->occurredAt->toAtomString(',
    ],
    'resources/views/feeds/issue-changes.blade.php' => [
        '->created_at ?? now())->toAtomString(',
        '$journal->created_at->toAtomString(',
    ],
];

/**
 * @return list<string>
 */
function dateTimeDisplayFiles(): array
{
    return collect([resource_path('views'), app_path('Support/Dashboard'), app_path('Mail'), app_path('Notifications')])
        ->flatMap(fn (string $root): array => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS))))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->filter(fn (string $path): bool => str_ends_with($path, '.php'))
        ->values()->all();
}

/**
 * @return list<string> the offending snippets in a file
 */
function directDateTimeFormatting(string $contents): array
{
    $timestamp = '(?:\$[\w>\-?]*?(?:_at|committed_on|closed_on|created_on|updated_on|occurredAt|updatedAt)(?: \?\? now\(\)\))?)';
    $formatting = '(?:format\((?!\))|toDateTimeString\(|toDateString\(|toTimeString\(|toAtomString\(|isoFormat\(|translatedFormat\(|diffForHumans\(|toFormattedDateString\(|toDayDateTimeString\()';

    $patterns = [
        // $issue->created_at->format(…), $journal->updated_at?->toDateString()
        '/'.$timestamp.'\??->'.$formatting.'/',
        // any hand-written time of day: ->format('Y-m-d H:i')
        '/->format\(\s*[\'"][^\'"]*[HhGgi][^\'"]*[\'"]\s*\)/',
        // a timestamp echoed as it is: {{ $issue->updated_at }}
        '/\{\{\s*\$[\w>\-?]*(?:_at|committed_on|closed_on|occurredAt|updatedAt)\s*\}\}/',
        // the server's today instead of the viewer's (DateTimes::today())
        '/(?<![\w:>])(?:now|today)\(\)->(?:toDateString|format|year|month|day|isToday)\b/',
    ];

    return collect($patterns)
        ->flatMap(function (string $pattern) use ($contents): array {
            preg_match_all($pattern, $contents, $matches);

            return $matches[0];
        })
        ->values()->all();
}

test('views format stored times through DateTimes', function () {
    $offending = [];

    foreach (dateTimeDisplayFiles() as $path) {
        $relative = ltrim(str_replace(base_path(), '', $path), '/');
        $allowed = DATE_TIME_DISPLAY_ALLOWED[$relative] ?? [];

        foreach (directDateTimeFormatting((string) file_get_contents($path)) as $snippet) {
            if (! collect($allowed)->contains(fn (string $allowedSnippet) => str_contains($snippet, $allowedSnippet) || str_contains($allowedSnippet, $snippet))) {
                $offending[] = "{$relative}: {$snippet}";
            }
        }
    }

    expect($offending)->toBe([]);
});

test('the guard catches direct formatting of a stored time', function (string $code) {
    expect(directDateTimeFormatting($code))->not->toBe([]);
})->with([
    'format' => ["{{ \$issue->created_at->format('Y-m-d') }}"],
    'nullsafe' => ['{{ $journal->updated_at?->toDateString() }}'],
    'time literal' => ["{{ \$changeset->when->format('Y-m-d H:i') }}"],
    'raw echo' => ['{{ $issue->updated_at }}'],
    'server today' => ['$this->spent_on = now()->toDateString();'],
    'diff for humans' => ['{{ $news->created_at->diffForHumans() }}'],
]);

test('the guard lets the helper and date-only form values through', function (string $code) {
    expect(directDateTimeFormatting($code))->toBe([]);
})->with([
    'helper' => ['{{ \App\Support\Format\DateTimes::dateTime($issue->created_at) }}'],
    'helper today' => ['\App\Support\Format\DateTimes::today()->toDateString()'],
    'date form value' => ['$this->start_date = $issue->start_date?->toDateString();'],
    'custom field format' => ['$field->format()->options($field)'],
]);
