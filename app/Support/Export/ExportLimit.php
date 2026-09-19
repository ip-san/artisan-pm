<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Models\Setting;

/**
 * Redmine's `issues_export_limit`: the most issues one CSV or PDF export of
 * an issue list contains (default 500). Capped here so a mistyped huge value
 * cannot make an export load an unbounded result set into memory.
 */
final class ExportLimit
{
    public const DEFAULT = 500;

    public const MAXIMUM = 5000;

    public static function issues(): int
    {
        $limit = (int) Setting::get('issues_export_limit', self::DEFAULT);

        return $limit >= 1 ? min($limit, self::MAXIMUM) : self::DEFAULT;
    }
}
