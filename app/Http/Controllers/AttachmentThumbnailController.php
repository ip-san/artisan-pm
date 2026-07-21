<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves an image attachment's small preview (the 'thumb' conversion
 * registered on every HasMedia model) inline rather than as a forced
 * download — matches Redmine's Attachment#thumbnail. Same authorization
 * boundary as AttachmentController, and 404s for attachments with no
 * generated thumbnail (non-image files, or ones uploaded before this
 * conversion existed).
 */
final class AttachmentThumbnailController extends Controller
{
    public function __invoke(Media $media): BinaryFileResponse
    {
        $model = $media->model;

        abort_if($model === null, 404);

        Gate::authorize('view', $model);

        abort_unless($media->hasGeneratedConversion('thumb'), 404);

        return response()->file($media->getPath('thumb'));
    }
}
