<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Renames an attachment's file the way Redmine's edit_all page does. The
 * media library keys a file's location and its generated conversions on the
 * file name, so the stored files move together with the record.
 */
final class AttachmentRenamer
{
    /**
     * A name that is safe to store: no path parts, no control characters,
     * not blank and not hidden-only.
     */
    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && mb_strlen($name) <= 255
            && $name === basename(str_replace('\\', '/', $name))
            && ! preg_match('/[\x00-\x1f\x7f]/', $name)
            && ! in_array($name, ['.', '..'], true);
    }

    public static function rename(Media $media, string $newName): void
    {
        if ($newName === $media->file_name) {
            return;
        }

        $disk = Storage::disk($media->disk);
        $conversions = array_keys($media->generated_conversions ?? []);

        $moves = [['from' => $media->getPathRelativeToRoot(), 'conversion' => null]];

        foreach ($conversions as $conversion) {
            $moves[] = ['from' => $media->getPathRelativeToRoot($conversion), 'conversion' => $conversion];
        }

        $media->file_name = $newName;
        $media->name = pathinfo($newName, PATHINFO_FILENAME);

        foreach ($moves as $move) {
            $to = $media->getPathRelativeToRoot($move['conversion'] ?? '');

            if ($disk->exists($move['from'])) {
                $disk->move($move['from'], $to);
            }
        }

        $media->save();
    }
}
