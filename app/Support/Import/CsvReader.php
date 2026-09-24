<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Reads an uploaded import file as CSV records rather than as lines, so a
 * quoted value may span several lines (a long description or custom field
 * text, as Redmine's Import#read_rows accepts through Ruby's CSV). A UTF-8
 * byte order mark — what Excel writes at the start of a "CSV UTF-8" file —
 * is dropped from the first header. A blank line stays a record (of one
 * null cell), so it fails as an empty row as it does in Redmine.
 */
final class CsvReader
{
    private const string UTF8_BOM = "\u{FEFF}";

    /**
     * The first record of the file (the header row), or [] for an empty file.
     *
     * @return list<string>
     */
    public static function header(string $path): array
    {
        foreach (self::records($path) as $record) {
            return array_map(fn (?string $cell) => (string) $cell, $record);
        }

        return [];
    }

    /**
     * The header and the records below it.
     *
     * @return array{header: list<string>, rows: list<list<string|null>>}
     */
    public static function read(string $path): array
    {
        $records = iterator_to_array(self::records($path), false);
        $header = array_map(fn (?string $cell) => (string) $cell, array_shift($records) ?? []);

        return ['header' => $header, 'rows' => $records];
    }

    /**
     * @return \Generator<int, list<string|null>>
     */
    private static function records(string $path): \Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        try {
            // Skipped before parsing so a quoted first header still reads
            // as quoted.
            if (fread($handle, strlen(self::UTF8_BOM)) !== self::UTF8_BOM) {
                rewind($handle);
            }

            while (($record = fgetcsv($handle, escape: '')) !== false) {
                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }
}
