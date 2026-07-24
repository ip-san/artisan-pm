<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PendingUpload;
use App\Support\Attachments\AttachmentValidationRules;
use App\Support\Attachments\PendingUploadToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Matches Redmine's POST /uploads.json: the client sends the raw file
 * bytes as the request body (not multipart), gets back a token, and
 * redeems it later by including {token, filename} in an issue's `uploads`
 * array (see IssueController::store()/update()). The uploaded file has
 * nowhere to live yet — see PendingUpload's own docblock for why this
 * app needs an explicit holder model where Redmine's Attachment#container
 * can simply be null.
 */
final class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $content = $request->getContent();

        if ($content === '') {
            return response()->json(['errors' => ['アップロードするファイルの内容がありません。']], 422);
        }

        if (strlen($content) > AttachmentValidationRules::maxSizeInBytes()) {
            return response()->json(['errors' => ['ファイルサイズが上限を超えています。']], 422);
        }

        $filename = (string) ($request->query('filename') ?: Str::random(16));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension !== '' && ! AttachmentValidationRules::isExtensionAllowed($extension)) {
            return response()->json(['errors' => ['このファイル形式は許可されていません。']], 422);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($tempPath, $content);

        $pendingUpload = PendingUpload::create(['user_id' => $request->user()->id]);
        $media = $pendingUpload->addMedia($tempPath)->usingFileName($filename)->toMediaCollection('pending');

        return response()->json([
            'upload' => ['id' => $media->id, 'token' => PendingUploadToken::generate($media)],
        ], 201);
    }
}
