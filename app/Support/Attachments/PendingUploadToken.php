<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\PendingUpload;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Matches Redmine's Attachment#token ("{id}.{digest}") / ::find_by_token,
 * reusing Media's own auto-generated `uuid` column (see
 * Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid) as the
 * unguessable component instead of a separately computed content digest —
 * both serve the same purpose of making the token unforgeable from the id
 * alone. A token only resolves while the underlying Media row is still
 * owned by a PendingUpload (i.e. unclaimed); once claimed via
 * Media::move(), the same id+uuid no longer matches this lookup, mirroring
 * Redmine's own `container.nil?` one-time-use check.
 */
final class PendingUploadToken
{
    public static function generate(Media $media): string
    {
        return "{$media->id}.{$media->uuid}";
    }

    public static function resolve(string $token): ?Media
    {
        if (preg_match('/^(\d+)\.([0-9a-f-]{36})$/', $token, $matches) !== 1) {
            return null;
        }

        return Media::query()
            ->where('id', $matches[1])
            ->where('uuid', $matches[2])
            ->where('model_type', (new PendingUpload)->getMorphClass())
            ->first();
    }
}
