<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Support\Preferences\ProjectJumpBox;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feeds the project jump box's "recently used" list: a signed-in user's GET
 * of any project page counts as using that project (Redmine does the same
 * in ApplicationController#find_project).
 */
final class RecordRecentProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $project = $request->route('project');
        $user = $request->user();

        if ($request->isMethod('GET') && $user !== null && $project instanceof Project && $response->isSuccessful() && $user->can('view', $project)) {
            ProjectJumpBox::projectUsed($user, $project);
        }

        return $response;
    }
}
