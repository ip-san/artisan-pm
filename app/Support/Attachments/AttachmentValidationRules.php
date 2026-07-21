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

    private static function extensionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());
            $allowed = self::extensionList('attachment_extensions_allowed');

            if ($allowed !== []) {
                if (! in_array($extension, $allowed, true)) {
                    $fail('このファイル形式は許可されていません。');
                }

                return;
            }

            $denied = self::extensionList('attachment_extensions_denied');

            if (in_array($extension, $denied, true)) {
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
