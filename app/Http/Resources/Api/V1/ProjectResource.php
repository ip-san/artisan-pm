<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\IssueCategory;
use App\Models\Project;
use App\Models\Tracker;
use App\Support\Api\CustomFieldPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Project $resource
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        // Single-project responses (show/store/update) load these here; the
        // index eager-loads them so the list stays free of N+1 queries.
        $project->loadMissing(['defaultVersion', 'defaultAssignedTo', 'defaultAssignedToGroup', 'defaultIssueQuery']);

        $includes = $this->includes($request);

        return [
            'id' => $project->id,
            'identifier' => $project->identifier,
            'name' => $project->name,
            'description' => $project->description,
            'homepage' => $project->homepage,
            'is_public' => $project->is_public,
            'inherit_members' => $project->inherit_members,
            'status' => $project->status->value,
            'parent_id' => $project->parent_id,
            'default_version' => $project->defaultVersion !== null
                ? ['id' => $project->defaultVersion->id, 'name' => $project->defaultVersion->name]
                : null,
            'default_issue_query' => $project->defaultIssueQuery !== null
                ? ['id' => $project->defaultIssueQuery->id, 'name' => $project->defaultIssueQuery->name]
                : null,
            'default_assignee' => match (true) {
                $project->defaultAssignedTo !== null => ['id' => $project->defaultAssignedTo->id, 'name' => $project->defaultAssignedTo->displayName(), 'type' => 'user'],
                $project->defaultAssignedToGroup !== null => ['id' => $project->defaultAssignedToGroup->id, 'name' => $project->defaultAssignedToGroup->name, 'type' => 'group'],
                default => null,
            },
            'custom_fields' => CustomFieldPayload::read($project),
            // Each of these is only present for ?include=trackers,
            // issue_categories,enabled_modules,time_entry_activities,
            // issue_custom_fields (Redmine's ProjectsHelper#render_api_includes).
            'trackers' => $this->when(
                in_array('trackers', $includes, true),
                fn () => $project->trackers->map(fn (Tracker $tracker) => ['id' => $tracker->id, 'name' => $tracker->name])->values()->all(),
            ),
            'issue_categories' => $this->when(
                in_array('issue_categories', $includes, true),
                fn () => $project->issueCategories->map(fn (IssueCategory $category) => ['id' => $category->id, 'name' => $category->name])->values()->all(),
            ),
            'time_entry_activities' => $this->when(
                in_array('time_entry_activities', $includes, true),
                fn () => $project->activities()->map(fn (Enumeration $activity) => ['id' => $activity->id, 'name' => $activity->name])->values()->all(),
            ),
            'enabled_modules' => $this->when(
                in_array('enabled_modules', $includes, true),
                fn () => $project->loadMissing('moduleAssignments')->moduleAssignments->map(fn ($assignment) => ['id' => $assignment->id, 'name' => $assignment->module->value])->values()->all(),
            ),
            'issue_custom_fields' => $this->when(
                in_array('issue_custom_fields', $includes, true),
                fn () => CustomField::query()
                    ->where('customized_type', CustomizableType::Issue)
                    ->with('projects')
                    ->orderBy('position')
                    ->get()
                    ->filter(fn (CustomField $field) => $field->appliesToProject($project))
                    ->map(fn (CustomField $field) => ['id' => $field->id, 'name' => $field->name])
                    ->values()
                    ->all(),
            ),
            'created_at' => $project->created_at->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function includes(Request $request): array
    {
        return collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $key) => trim($key))
            ->filter()
            ->values()
            ->all();
    }
}
