<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttachmentResource;
use App\Models\Issue;
use App\Services\IssueService;
use App\Support\Attachments\AttachmentValidationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Redmine's attachments API: show, download, update (description, file
 * name) and destroy, addressed by attachment id whatever it belongs to. An
 * attachment is only reachable through the policy of the thing it is
 * attached to — view to read or download it, update to change or delete
 * it — so a model without such a policy (a pending upload, for one) is
 * simply forbidden. Uploading is POST /uploads plus an `uploads` entry on
 * the owner, as in Redmine.
 */
final class AttachmentController extends Controller
{
    public function show(Request $request, Media $media): AttachmentResource
    {
        $this->authorizeOn($media, 'view');

        return new AttachmentResource($media);
    }

    /**
     * Streams the file with the same rules as the browser download,
     * counting the download; the API-token counterpart of
     * attachments.show, whose route needs a session.
     */
    public function download(Media $media): BinaryFileResponse
    {
        $this->authorizeOn($media, 'view');

        $media->setCustomProperty('download_count', ((int) $media->getCustomProperty('download_count', 0)) + 1);
        $media->save();

        return response()->download($media->getPath(), $media->file_name);
    }

    public function update(Request $request, Media $media): JsonResponse
    {
        $this->authorizeOn($media, 'update');

        $data = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filename' => ['sometimes', 'string', 'max:255'],
        ]);

        if (array_key_exists('filename', $data) && ! AttachmentValidationRules::isExtensionAllowed(pathinfo($data['filename'], PATHINFO_EXTENSION))) {
            return response()->json(['message' => 'The file name is not allowed.', 'errors' => ['filename' => ['This file type is not allowed.']]], 422);
        }

        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $media->setCustomProperty('description', $description !== '' ? $description : null);
        }

        if (array_key_exists('filename', $data) && trim($data['filename']) !== '') {
            $media->file_name = basename(trim($data['filename']));
            $media->name = pathinfo($media->file_name, PATHINFO_FILENAME);
        }

        $media->save();

        return response()->json(status: 204);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $this->authorizeOn($media, 'update');

        $owner = $media->model;

        $media->delete();

        // Redmine journals an issue's attachment removal; the other
        // containers have no history to record it in.
        if ($owner instanceof Issue) {
            app(IssueService::class)->journalizeAttachment($owner, $media, added: false, actor: $request->user());
        }

        return response()->json(status: 204);
    }

    /**
     * 404 for an attachment with no owner; otherwise the owner's policy
     * decides, and a model with no such ability denies.
     */
    private function authorizeOn(Media $media, string $ability): void
    {
        $owner = $media->model;

        abort_if($owner === null, 404);

        Gate::authorize($ability, $owner);
    }
}
