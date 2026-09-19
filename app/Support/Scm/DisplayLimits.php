<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Models\Setting;

/**
 * Redmine's `diff_max_lines_displayed` and `file_max_size_displayed`:
 * caps on how much of a diff or file the repository pages render inline.
 * 0 lifts the cap.
 */
final class DisplayLimits
{
    public const int DEFAULT_DIFF_MAX_LINES = 1500;

    public const int DEFAULT_FILE_MAX_SIZE_KB = 512;

    public static function maxDiffLines(): int
    {
        return max(0, (int) Setting::get('diff_max_lines_displayed', self::DEFAULT_DIFF_MAX_LINES));
    }

    public static function maxFileSizeKb(): int
    {
        return max(0, (int) Setting::get('file_max_size_displayed', self::DEFAULT_FILE_MAX_SIZE_KB));
    }

    /**
     * The first maxDiffLines() lines of $diff, and whether anything was cut.
     *
     * @return array{text: string, truncated: bool}
     */
    public static function truncateDiff(string $diff): array
    {
        $max = self::maxDiffLines();

        if ($max === 0) {
            return ['text' => $diff, 'truncated' => false];
        }

        $lines = explode("\n", $diff);

        if (count($lines) <= $max) {
            return ['text' => $diff, 'truncated' => false];
        }

        return ['text' => implode("\n", array_slice($lines, 0, $max)), 'truncated' => true];
    }

    public static function fileTooLargeToDisplay(int $bytes): bool
    {
        $max = self::maxFileSizeKb();

        return $max > 0 && $bytes > $max * 1024;
    }
}
