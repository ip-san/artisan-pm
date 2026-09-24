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
     * Several attachments as an `attachments` array, their uploaders
     * looked up at once (issues, news, wiki pages).
     *
     * @param  iterable<int, Media>  $medias
     * @return array<int, array<string, mixed>>
     */
    public static function listFor(iterable $medias, Request $request): array
    {
        $medias = collect($medias);
        $request->attributes->set('attachment_uploaders', AttachmentUploader::usersFor($medias));

        return $medias->map(fn (Media $media) => (new self($media))->resolve($request))->values()->all();
    }

    /**
     * Whether the request's comma-separated `include` names $key (Redmine's
     * include_in_api_response?).
     */
    public static function included(Request $request, string $key): bool
    {
        return in_array($key, array_map('trim', explode(',', (string) $request->query('include', ''))), true);
    }

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
            'author' => $author !== null ? ['id' => $author->id, 'name' => $author->displayName()] : null,
            'created_at' => $media->created_at->toIso8601String(),
        ];
    }
}
