<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttachmentResource;
use App\Models\Project;
use App\Models\Version;
use App\Support\Attachments\AttachmentUploader;
use App\Support\Attachments\PendingUploadAttacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Redmine's project Files module API: GET /projects/{id}/files lists the
 * files of the project and of each of its versions, POST attaches a
 * previously uploaded file (token from POST /uploads) to the project or to
 * one of its versions. Read needs view_files, write needs manage_files.
 */
final class FileController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [Version::class, $project]);

        $entries = [];

        $containers = collect([[null, $project->files()]])->concat(
            $project->versions()->orderByDesc('due_date')->get()->map(fn (Version $version) => [$version, $version->files()])
        );

        $allMedia = $containers->flatMap(fn (array $container) => $container[1]);
        $request->attributes->set('attachment_uploaders', AttachmentUploader::usersFor($allMedia));

        foreach ($containers as [$version, $files]) {
            foreach ($files as $media) {
                $entries[] = [
                    ...(new AttachmentResource($media))->resolve($request),
                    ...($version !== null ? ['version' => ['id' => $version->id, 'name' => $version->name]] : []),
                ];
            }
        }

        return response()->json(['data' => $entries]);
    }

    /**
     * Accepts Redmine's two body shapes: `{token, filename?, description?,
     * version_id?}` at the top level or the same under a `file` key. The
     * version, when given, must belong to this project.
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $input = $request->input('file');
        $upload = is_array($input) ? $input : $request->only(['token', 'filename', 'description', 'version_id']);
        $request->merge(['upload' => $upload]);

        $data = $request->validate([
            'upload.token' => ['required', 'string'],
            'upload.filename' => ['nullable', 'string', 'max:255'],
            'upload.description' => ['nullable', 'string', 'max:255'],
            'upload.version_id' => ['nullable', 'integer'],
        ]);

        $upload = $data['upload'];
        $versionId = $upload['version_id'] ?? null;

        $target = $versionId === null ? $project : $project->versions()->find($versionId);

        abort_if($target === null, 404);

        Gate::authorize('manageFiles', $target);

        $media = PendingUploadAttacher::attach($upload, $target, 'files');

        if ($media === null) {
            return response()->json(['message' => 'The upload token is invalid or has expired.', 'errors' => ['token' => ['Invalid upload token.']]], 422);
        }

        return (new AttachmentResource($media))->response()->setStatusCode(201);
    }
}
