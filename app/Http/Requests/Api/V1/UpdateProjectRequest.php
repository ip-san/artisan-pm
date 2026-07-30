<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ProjectModuleKey;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Matches projects/form.blade.php's save(): a plain field edit only needs
 * update (edit_project), but changing parent_id specifically needs
 * createSubproject on the new parent — re-checked here rather than at
 * mount time, since parent_id is client-submitted on every request. Only
 * *changing away from* the current parent re-triggers that check; leaving
 * parent_id at its existing value (or omitting it) never does, matching
 * the same reasoning the web form's doc comment gives.
 */
final class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        if (! $this->user()->can('update', $project)) {
            return false;
        }

        if (! $this->has('parent_id')) {
            return true;
        }

        $newParentId = $this->integer('parent_id') ?: null;

        if ($newParentId === $project->parent_id) {
            return true;
        }

        if ($newParentId === null) {
            return true;
        }

        $parent = Project::query()->find($newParentId);

        return $parent !== null && $this->user()->can('createSubproject', $parent);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');
        $descendantIds = $project->descendants()->get(['id'])->pluck('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'identifier' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('projects', 'identifier')->ignore($project->id)],
            'description' => ['nullable', 'string'],
            'is_public' => ['boolean'],
            // Excludes the project itself and its own descendants —
            // either would create a cycle in the nested set, matching
            // availableParents()'s exclusion in the web form.
            'parent_id' => ['nullable', 'exists:projects,id', Rule::notIn([$project->id, ...$descendantIds])],
            'tracker_ids' => ['sometimes', 'array', 'min:1'],
            'tracker_ids.*' => ['exists:trackers,id'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => [Rule::in(array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::cases()))],
        ];
    }
}
