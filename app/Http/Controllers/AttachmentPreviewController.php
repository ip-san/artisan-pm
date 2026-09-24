<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Attachments\AttachmentFieldValue;
use App\Support\Attachments\AttachmentPreview;
use Illuminate\Contracts\View\View;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Redmine's attachments#show: a page for one attachment with its content
 * (image, PDF, text or diff) or, for other types, its details, plus the
 * previous/next file of the same record.
 */
final class AttachmentPreviewController extends Controller
{
    public function __invoke(Media $media): View
    {
        $model = $media->model;

        abort_if($model === null, 404);

        AttachmentFieldValue::authorizeView($media, request()->user());

        $siblings = $model->getMedia($media->collection_name)->filter(fn (Media $sibling) => AttachmentFieldValue::visibleTo($sibling, request()->user()))->values();
        $position = $siblings->search(fn (Media $sibling) => $sibling->is($media));
        $kind = AttachmentPreview::kind($media);

        return view('attachments.preview', [
            'media' => $media,
            'kind' => $kind,
            'text' => in_array($kind, [AttachmentPreview::TEXT, AttachmentPreview::DIFF], true) ? AttachmentPreview::textOf($media) : null,
            'previous' => $position !== false && $position > 0 ? $siblings[$position - 1] : null,
            'next' => $position !== false ? $siblings->get($position + 1) : null,
            'position' => $position === false ? null : $position + 1,
            'total' => $siblings->count(),
        ]);
    }
}
