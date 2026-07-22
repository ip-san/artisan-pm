<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Streams a repository file's raw bytes at HEAD as a forced download —
 * unlike repository.entry (a Volt component that renders text content
 * inline and refuses binary files outright), this route works for any
 * file since it never tries to interpret the bytes as text.
 */
final class RepositoryRawController extends Controller
{
    public function __invoke(Project $project, string $path): Response
    {
        Gate::authorize('browse', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $path = trim($path, '/');
        $content = $repository->adapter()->fileContentAt('HEAD', $path);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = ($finfo !== false ? finfo_buffer($finfo, $content) : false) ?: 'application/octet-stream';
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.addslashes(basename($path)).'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
