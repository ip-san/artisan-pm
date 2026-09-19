<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Setting;

/**
 * Redmine's `link_copied_issue` and `copy_attachments_on_issue_copy`: each
 * is `yes` (always), `no` (never) or `ask` (the copy form's checkbox
 * decides, checked by default). Both default to `ask`.
 */
final class CopyOptions
{
    public const MODES = ['yes', 'no', 'ask'];

    public static function linkMode(): string
    {
        return self::mode('link_copied_issue');
    }

    public static function attachmentsMode(): string
    {
        return self::mode('copy_attachments_on_issue_copy');
    }

    /**
     * Whether the copy should link back to (or carry the attachments of)
     * its source: the setting wins unless it is `ask`, in which case the
     * checkbox value does.
     */
    public static function resolve(string $mode, bool $checkbox): bool
    {
        return match ($mode) {
            'yes' => true,
            'no' => false,
            default => $checkbox,
        };
    }

    private static function mode(string $key): string
    {
        $value = Setting::get($key, 'ask');

        return in_array($value, self::MODES, true) ? $value : 'ask';
    }
}
