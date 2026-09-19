<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Support\Scm\CodesetConverter;
use App\Support\Scm\DisplayLimits;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Mime\MimeTypes;

/**
 * What the attachment view page can show, decided like Redmine's
 * AttachmentsController#show: a diff/patch as a diff, an image inline, a PDF
 * embedded, a text file (up to file_max_size_displayed) as text, anything
 * else with just its details and a download link.
 */
final class AttachmentPreview
{
    public const string IMAGE = 'image';

    public const string PDF = 'pdf';

    public const string DIFF = 'diff';

    public const string TEXT = 'text';

    public const string OTHER = 'other';

    /**
     * Image types a browser draws inline safely from our own origin.
     *
     * @var array<int, string>
     */
    private const array IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml'];

    /**
     * @var array<int, string>
     */
    private const array TEXT_EXTENSIONS = ['txt', 'text', 'md', 'markdown', 'csv', 'tsv', 'log', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'html', 'htm', 'css', 'js', 'ts', 'php', 'rb', 'py', 'java', 'c', 'h', 'cpp', 'go', 'rs', 'sh', 'sql'];

    public static function kind(Media $media): string
    {
        $extension = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        $mime = self::mimeOf($media, $extension);

        return match (true) {
            in_array($extension, ['diff', 'patch'], true) => self::tooLargeForText($media) ? self::OTHER : self::DIFF,
            in_array($mime, self::IMAGE_MIMES, true) => self::IMAGE,
            $mime === 'application/pdf' => self::PDF,
            (str_starts_with($mime, 'text/') || in_array($extension, self::TEXT_EXTENSIONS, true)) && ! self::tooLargeForText($media) => self::TEXT,
            default => self::OTHER,
        };
    }

    /**
     * The links worth showing: anything with an inline representation.
     */
    public static function isPreviewable(Media $media): bool
    {
        return self::kind($media) !== self::OTHER;
    }

    /**
     * Only image and PDF files are streamed inline; everything else stays a
     * download so an uploaded HTML or script file is never served to run.
     */
    public static function isServedInline(Media $media): bool
    {
        return in_array(self::kind($media), [self::IMAGE, self::PDF], true);
    }

    /**
     * The file's text as UTF-8, or null when it cannot be shown as text
     * (missing, unreadable, or not text in any configured encoding).
     */
    public static function textOf(Media $media): ?string
    {
        $path = $media->getPath();

        if (! is_readable($path)) {
            return null;
        }

        return CodesetConverter::convertStrictly((string) file_get_contents($path));
    }

    /**
     * The type by file name, as Redmine's MimeType.of does: what the
     * extension says, falling back to what was detected at upload. The
     * detected type alone would call a zip that happens to start with text
     * bytes a text file.
     */
    private static function mimeOf(Media $media, string $extension): string
    {
        $byExtension = $extension !== '' ? (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null) : null;

        return strtolower($byExtension ?? (string) $media->mime_type);
    }

    private static function tooLargeForText(Media $media): bool
    {
        return DisplayLimits::fileTooLargeToDisplay((int) $media->size);
    }
}
