<?php

declare(strict_types=1);

namespace App\Services;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Edits an existing attachment. Authorization stays with the caller.
 */
final class AttachmentService
{
    /**
     * Sets Redmine's Attachment#description from what was typed: trimmed,
     * and a blank entry clears it.
     */
    public function describe(Media $media, ?string $input): void
    {
        $description = trim((string) $input);

        $media->setCustomProperty('description', $description !== '' ? $description : null);
        $media->save();
    }
}
