<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use ZipArchive;

/**
 * Bundles attachments into one ZIP for the "download all" link, as Redmine's
 * Attachment.archive_attachments does: files that share a name get a
 * `(1)`, `(2)` … suffix before the extension, and unreadable files are
 * skipped.
 */
final class AttachmentArchive
{
    public const int DEFAULT_MAX_SIZE_KB = 102400;

    public static function maxSizeKb(): int
    {
        return max(0, (int) Setting::get('bulk_download_max_size', self::DEFAULT_MAX_SIZE_KB));
    }

    /**
     * @param  Collection<int, Media>  $attachments
     */
    public static function exceedsLimit(Collection $attachments): bool
    {
        $max = self::maxSizeKb();

        return $max > 0 && $attachments->sum('size') > $max * 1024;
    }

    /**
     * @param  Collection<int, Media>  $attachments
     * @return string|null path of a temporary ZIP file (the caller deletes
     *                     it), or null when no attachment is readable
     */
    public static function build(Collection $attachments): ?string
    {
        $readable = $attachments->filter(fn (Media $media) => is_readable($media->getPath()));

        if ($readable->isEmpty()) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'attachments-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $used = [];

        foreach ($readable as $media) {
            $name = self::uniqueName($media->file_name, $used);
            $used[] = $name;
            $zip->addFile($media->getPath(), $name);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $used
     */
    private static function uniqueName(string $fileName, array $used): string
    {
        $name = $fileName;
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        for ($count = 1; in_array($name, $used, true); $count++) {
            $name = $base.'('.$count.')'.($extension !== '' ? ".{$extension}" : '');
        }

        return $name;
    }
}
