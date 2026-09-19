<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\Attachments\AttachmentUploader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * An attachment as Redmine's render_api_attachment describes it. author is
 * the uploader recorded by AttachmentUploader (null for files that
 * predate it); content_url is the browser (session) download and
 * download_url its token-authenticated API counterpart. Authors are
 * resolved through the `uploaders` request attribute when a list
 * pre-loaded them, otherwise one lookup per attachment.
 *
 * @property Media $resource
 */
final class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;
        $uploaders = $request->attributes->get('attachment_uploaders');
        $author = $uploaders !== null
            ? ($uploaders[AttachmentUploader::idOf($media)] ?? null)
            : AttachmentUploader::userOf($media);

        return [
            'id' => $media->id,
            'filename' => $media->file_name,
            'filesize' => $media->size,
            'content_type' => $media->mime_type,
            'description' => $media->getCustomProperty('description'),
            'content_url' => route('attachments.show', $media),
            'download_url' => route('api.attachments.download', $media),
            'downloads' => (int) $media->getCustomProperty('download_count', 0),
            'author' => $author !== null ? ['id' => $author->id, 'name' => $author->name] : null,
            'created_at' => $media->created_at->toIso8601String(),
        ];
    }
}
