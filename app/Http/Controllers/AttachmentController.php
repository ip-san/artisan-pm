<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams an attachment as a forced download after checking the requester
 * can view the model it's attached to. Attachments live on the private
 * 'local' disk specifically so this route is the only way to reach them —
 * see config/media-library.php.
 */
final class AttachmentController extends Controller
{
    public function __invoke(Media $media): BinaryFileResponse
    {
        $model = $media->model;

        abort_if($model === null, 404);

        Gate::authorize('view', $model);

        $media->setCustomProperty('download_count', ((int) $media->getCustomProperty('download_count', 0)) + 1);
        $media->save();

        return response()->download($media->getPath(), $media->file_name);
    }
}
