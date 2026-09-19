<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Attachments\AttachmentPreview;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams an image or PDF attachment for display in the preview page.
 * Anything else is refused: only types a browser can show safely are
 * served inline, and even those get a sandboxing CSP, as Redmine's
 * AttachmentsController#send_file does.
 */
final class AttachmentInlineController extends Controller
{
    public function __invoke(Media $media): BinaryFileResponse
    {
        $model = $media->model;

        abort_if($model === null, 404);

        Gate::authorize('view', $model);

        abort_unless(AttachmentPreview::isServedInline($media), 404);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($media->file_name).'"',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
