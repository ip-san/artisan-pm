<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\PendingUpload;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Redeems a `{token, filename, description}` upload entry — what a client
 * sends after POST /uploads — by moving the pending file onto its real
 * owner. Shared by every REST endpoint that accepts uploads (issues, the
 * project Files module).
 */
final class PendingUploadAttacher
{
    /**
     * @param  array<string, mixed>  $upload
     * @return Media|null null when the token does not resolve to a pending file
     */
    public static function attach(array $upload, HasMedia $target, string $collection): ?Media
    {
        $media = PendingUploadToken::resolve((string) ($upload['token'] ?? ''));

        if ($media === null) {
            return null;
        }

        $pendingUploadId = $media->model_id;
        $filename = trim((string) ($upload['filename'] ?? ''));
        $description = trim((string) ($upload['description'] ?? ''));

        // The original filename already passed the extension allow/deny list
        // at upload time — a rename-on-attach override must be re-checked
        // too, or it'd let a client upload as "notes.txt" (allowed) and
        // relabel it "malware.exe" (denied) right here, bypassing the check
        // entirely. Falls back to the original, already-validated name
        // rather than dropping the attachment.
        if ($filename !== '' && ! AttachmentValidationRules::isExtensionAllowed(pathinfo($filename, PATHINFO_EXTENSION))) {
            $filename = '';
        }

        // Custom properties carry over through move() (it's a copy+delete
        // under the hood — see Media::copy()), so the description has to be
        // set on the pre-move instance.
        if ($description !== '') {
            $media->setCustomProperty('description', $description);
            $media->save();
        }

        $moved = $media->move($target, $collection, '', $filename);

        PendingUpload::query()->whereKey($pendingUploadId)->delete();

        return $moved;
    }
}
