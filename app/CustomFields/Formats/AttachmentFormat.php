<?php

declare(strict_types=1);

namespace App\CustomFields\Formats;

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Support\Attachments\AttachmentValidationRules;
use App\Support\Attachments\PendingUploadToken;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Redmine's AttachmentFormat (B'-02, docs/design/gap-B-02.md): the value is
 * one file. The file is a media item of the customized record itself, in
 * the COLLECTION collection with the field's id in its custom properties,
 * and the stored value (value_int) is the media id — Redmine stores the
 * attachment id too. Setting the value needs the record, so HasCustomFields
 * hands it to AttachmentFieldValue::assign() instead of prepareValue().
 * Offered for every customizable type, like Redmine (B'-02b); never multiple, searchable or a
 * filter (Redmine: is_filter_supported = false).
 */
final class AttachmentFormat implements FormatContract
{
    public const string COLLECTION = 'custom_field_attachments';

    public function key(): CustomFieldFormat
    {
        return CustomFieldFormat::Attachment;
    }

    public function label(): string
    {
        return __('添付ファイル');
    }

    public function storageColumn(): string
    {
        return 'value_int';
    }

    /**
     * Only used by paths that can't carry a file (imports, e-mail
     * keywords): they never set this format's value.
     */
    public function prepareValue(mixed $input): mixed
    {
        return null;
    }

    public function castValue(mixed $stored, CustomField $field): mixed
    {
        return $stored === null ? null : (int) $stored;
    }

    /**
     * An uploaded file (checked against the site's size and extension
     * settings and the field's own extensions_allowed), an API upload
     * `{token}` that still resolves, or the current media id (whether it
     * really is this record's file is checked when the value is set).
     */
    public function validationRules(CustomField $field): array
    {
        return [function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if ($value === null || $value === '') {
                return;
            }

            if ($value instanceof UploadedFile) {
                if ($value->getSize() > AttachmentValidationRules::maxSizeInBytes()) {
                    $fail(__('ファイルサイズが上限を超えています。'));
                } elseif (! self::extensionAllowed($field, $value->getClientOriginalName())) {
                    $fail(__('このファイル形式は許可されていません。'));
                }

                return;
            }

            if (is_array($value)) {
                $media = PendingUploadToken::resolve((string) ($value['token'] ?? ''));

                if ($media === null) {
                    $fail(__('アップロードされたファイルが見つかりません。'));
                } elseif (! self::extensionAllowed($field, $media->file_name)) {
                    $fail(__('このファイル形式は許可されていません。'));
                }

                return;
            }

            if (! is_int($value) && ! ctype_digit((string) $value)) {
                $fail(__('ファイルを選択してください。'));
            }
        }];
    }

    public function options(CustomField $field): array
    {
        return [];
    }

    /**
     * The field's own extensions_allowed (Redmine's field attribute, a
     * comma or space separated list), on top of the site-wide settings.
     *
     * @return array<int, string>
     */
    public static function allowedExtensions(CustomField $field): array
    {
        $list = (string) ($field->format_options['extensions_allowed'] ?? '');

        return array_values(array_filter(array_map(
            fn (string $extension) => strtolower(ltrim(trim($extension), '.')),
            preg_split('/[\s,]+/', $list) ?: [],
        )));
    }

    public static function extensionAllowed(CustomField $field, string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! AttachmentValidationRules::isExtensionAllowed($extension)) {
            return false;
        }

        $allowed = self::allowedExtensions($field);

        return $allowed === [] || in_array($extension, $allowed, true);
    }
}
