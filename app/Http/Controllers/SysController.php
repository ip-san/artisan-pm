<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use App\Exceptions\ScmCommandFailedException;
use App\Jobs\RepositorySyncJob;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Redmine's repository management web service (`/sys/*`), the hook point for
 * post-receive scripts and reposman.rb. Authenticated by the shared
 * `sys_api_key` (see EnforceSysApiKey), not by a user, so it exposes only
 * what those scripts need: which projects have a repository, and a way to
 * trigger a sync.
 */
final class SysController extends Controller
{
    /**
     * Active projects with the repository module, each with its default
     * repository (`url` is its path on this server, or its remote URL — never its credentials).
     */
    public function projects(): JsonResponse
    {
        $projects = $this->repositoryProjects()->sortBy('identifier')->values();

        return response()->json($projects->map(fn (Project $project) => [
            'id' => $project->id,
            'identifier' => $project->identifier,
            'name' => $project->name,
            'is_public' => $project->is_public,
            'status' => $project->status->value,
            'repository' => ($repository = $project->repositories->firstWhere('is_default', true) ?? $project->repositories->first()) === null
                ? null
                : ['id' => $repository->id, 'url' => $repository->location()],
        ])->all());
    }

    /**
     * Queues a sync of every repository of the project given as `id` (its
     * numeric id or identifier), or of every project when omitted.
     */
    public function fetchChangesets(Request $request): Response
    {
        $projects = $this->repositoryProjects();
        $id = $request->input('id');

        if ($id !== null && $id !== '') {
            $projects = $projects->filter(fn (Project $project) => ctype_digit((string) $id)
                ? $project->id === (int) $id
                : $project->identifier === (string) $id);

            abort_if($projects->isEmpty(), 404);
        }

        foreach ($projects as $project) {
            foreach ($project->repositories as $repository) {
                // Under the "sync" queue driver the job runs inline: one
                // repository whose command can't run mustn't stop the rest.
                try {
                    RepositorySyncJob::dispatch($repository);
                } catch (ScmCommandFailedException $exception) {
                    report($exception);
                }
            }
        }

        return response('', 200);
    }

    /**
     * @return Collection<int, Project>
     */
    private function repositoryProjects(): Collection
    {
        return Project::query()
            ->where('status', ProjectStatus::Active)
            ->with(['repositories', 'moduleAssignments'])
            ->get()
            ->filter(fn (Project $project) => $project->hasModule(ProjectModuleKey::Repository))
            ->values();
    }
}
