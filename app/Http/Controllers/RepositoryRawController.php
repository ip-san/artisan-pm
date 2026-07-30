<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Repository;
use finfo;
use Illuminate\Http\Request;
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
    /**
     * Reads route parameters off the Request rather than typed method
     * arguments: `repository.raw` and `repository.raw.repo` share this one
     * method but declare `{path}` in a different position relative to
     * `{repositoryParam}` (absent entirely on the former). Empirically,
     * Laravel's controller-dispatch binding for this invokable controller
     * did not resolve those two routes' parameters purely by name — a
     * fix that made repository.raw.repo route correctly (reordering the
     * typed arguments to match its URI) broke repository.raw's binding of
     * `path` in the process (confirmed via a real HTTP GET, not just a
     * route-collection match). Pulling both by name from the route
     * explicitly sidesteps whatever binding-order sensitivity caused that.
     */
    public function __invoke(Project $project, Request $request): Response
    {
        Gate::authorize('browse', [Repository::class, $project]);

        $repositoryParam = $request->route('repositoryParam');
        $repository = $project->resolveRepository($repositoryParam);
        abort_if($repository === null, 404);

        $path = trim((string) $request->route('path'), '/');
        $content = $repository->adapter()->fileContentAt('HEAD', $path);

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.addslashes(basename($path)).'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
