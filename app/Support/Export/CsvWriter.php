<?php

declare(strict_types=1);

namespace App\Support\Export;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download the way every list export writes one (Redmine's
 * Redmine::Export::CSV): UTF-8 with a byte-order mark, so Excel detects
 * the encoding instead of showing mojibake, or Shift_JIS without one, in
 * the chosen separator, every cell passed through CsvCell. The encoding
 * and separator come from the export form, so anything outside the
 * offered choices falls back to the defaults rather than reaching the
 * file.
 */
final class CsvWriter
{
    /** @var array<int, string> */
    public const array ENCODINGS = ['UTF-8', 'SJIS-win'];

    /** @var array<int, string> */
    public const array SEPARATORS = [',', ';', "\t"];

    /**
     * @param  resource  $handle
     */
    private function __construct(
        private $handle,
        private readonly string $encoding,
        private readonly string $separator,
    ) {}

    /**
     * @param  Closure(self): void  $write  writes the rows
     */
    public static function download(string $filename, Closure $write, string $encoding = 'UTF-8', string $separator = ','): StreamedResponse
    {
        $encoding = in_array($encoding, self::ENCODINGS, true) ? $encoding : 'UTF-8';
        $separator = in_array($separator, self::SEPARATORS, true) ? $separator : ',';

        return response()->streamDownload(function () use ($write, $encoding, $separator): void {
            $handle = fopen('php://output', 'w');

            if ($encoding === 'UTF-8') {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $write(new self($handle, $encoding, $separator));

            fclose($handle);
        }, $filename);
    }

    /**
     * @param  array<int|string, mixed>  $cells
     */
    public function row(array $cells): void
    {
        $cells = CsvCell::row($cells);

        if ($this->encoding !== 'UTF-8') {
            $cells = array_map(fn (string $value) => mb_convert_encoding($value, $this->encoding, 'UTF-8'), $cells);
        }

        fputcsv($this->handle, $cells, $this->separator);
    }
}
