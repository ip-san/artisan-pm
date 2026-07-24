<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PendingUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A short-lived holder for a single file uploaded via the REST API's
 * POST /uploads endpoint before it's attached to a real record — matches
 * Redmine's Attachment#container being nullable until an issue/etc. claims
 * it by token. Spatie's `media` table requires a non-null owning model
 * (unlike Redmine's Attachment), so this model exists purely to give an
 * uploaded-but-not-yet-attached file somewhere to live; once claimed, the
 * Media row is moved (not copied) onto the real model via Media::move()
 * and this row is deleted. Rows older than PruneExpiredPendingUploadsJob's
 * cutoff (and their never-claimed Media) are garbage-collected — see that
 * job for the exact window.
 */
#[Fillable(['user_id'])]
final class PendingUpload extends Model implements HasMedia
{
    /** @use HasFactory<PendingUploadFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('pending')->singleFile();
    }

    public function pendingMedia(): ?Media
    {
        return $this->getFirstMedia('pending');
    }
}
