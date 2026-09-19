<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Attachments\AttachmentArchive;
use App\Support\Attachments\AttachmentContainers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Download all": every attachment of one record as a single ZIP, for
 * whoever may view that record. Refused above `bulk_download_max_size`.
 */
final class AttachmentBundleController extends Controller
{
    public function __invoke(string $type, int $id): BinaryFileResponse|RedirectResponse
    {
        $container = AttachmentContainers::find($type, $id);

        abort_if($container === null, 404);

        Gate::authorize('view', $container);

        $attachments = $container->getMedia('attachments');

        abort_if($attachments->isEmpty(), 404);

        if (AttachmentArchive::exceedsLimit($attachments)) {
            return redirect()->back()->with('error', sprintf('添付ファイルの合計サイズが上限(%sKB)を超えているため、まとめてダウンロードできません。', number_format(AttachmentArchive::maxSizeKb())));
        }

        $zipPath = AttachmentArchive::build($attachments);

        abort_if($zipPath === null, 404);

        return response()->download($zipPath, "{$type}-{$container->getKey()}-attachments.zip")->deleteFileAfterSend();
    }
}
