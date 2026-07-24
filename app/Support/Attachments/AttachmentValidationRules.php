<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\Setting;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Builds the validation rules for a new attachment upload from the
 * admin-configurable Settings (attachment_max_size in KB, plus an
 * allow-list/deny-list of extensions) rather than the previously
 * hardcoded media-library.max_file_size config value. The allow-list
 * wins when both are set, matching Redmine's own settings screen.
 */
final class AttachmentValidationRules
{
    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        $maxKb = (int) Setting::get('attachment_max_size', intdiv((int) config('media-library.max_file_size'), 1024));

        return ['file', "max:{$maxKb}", self::extensionRule()];
    }

    public static function maxSizeInBytes(): int
    {
        return (int) Setting::get('attachment_max_size', intdiv((int) config('media-library.max_file_size'), 1024)) * 1024;
    }

    /**
     * Shared by the closure-based file-upload rule above and by the REST
     * API's raw-octet-stream upload endpoint, which has no UploadedFile to
     * run through Laravel's validator.
     */
    public static function isExtensionAllowed(string $extension): bool
    {
        $extension = strtolower($extension);
        $allowed = self::extensionList('attachment_extensions_allowed');

        if ($allowed !== []) {
            return in_array($extension, $allowed, true);
        }

        return ! in_array($extension, self::extensionList('attachment_extensions_denied'), true);
    }

    private static function extensionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            if (! self::isExtensionAllowed($value->getClientOriginalExtension())) {
                $fail('このファイル形式は許可されていません。');
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private static function extensionList(string $settingKey): array
    {
        $raw = (string) Setting::get($settingKey, '');

        return collect(explode(',', $raw))
            ->map(fn (string $extension): string => strtolower(trim($extension, ". \t\n\r\0\x0B")))
            ->filter()
            ->values()
            ->all();
    }
}
