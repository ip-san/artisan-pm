<?php

declare(strict_types=1);

namespace App\Concerns;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Generates a small preview image for image attachments — matches
 * Redmine's Attachment#thumbnail (used by its thumbnail_tag helper).
 * Non-image files (PDFs, archives, ...) are silently skipped by Media
 * Library itself, since no ImageGenerator can process them.
 *
 * Collides with InteractsWithMedia's own no-op registerMediaConversions,
 * so every consumer must resolve it explicitly:
 *
 *     use HasThumbnails, InteractsWithMedia {
 *         HasThumbnails::registerMediaConversions insteadof InteractsWithMedia;
 *     }
 */
trait HasThumbnails
{
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->performOnCollections(...$this->thumbnailCollections())
            ->width(100)
            ->height(100);
    }

    /**
     * The media collection(s) thumbnails apply to — override when a model
     * doesn't use the default 'attachments' collection name (Project and
     * Version use 'files' instead).
     *
     * @return array<int, string>
     */
    protected function thumbnailCollections(): array
    {
        return ['attachments'];
    }
}
