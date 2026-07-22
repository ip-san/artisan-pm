<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Repository;
use finfo;
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

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.addslashes(basename($path)).'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
