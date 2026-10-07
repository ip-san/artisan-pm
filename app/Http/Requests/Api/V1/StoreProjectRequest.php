<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ProjectModuleKey;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Matches projects/form.blade.php's authorization: a top-level project may
 * only ever be created by an admin (ProjectPolicy::create() always returns
 * false, relying entirely on Gate::before's admin bypass), while a
 * subproject is gated on createSubproject against the specific parent —
 * so which check applies depends on whether parent_id was submitted.
 */
final class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $parentId = $this->integer('parent_id') ?: null;

        if ($parentId === null) {
            return $this->user()->can('create', Project::class);
        }

        $parent = Project::query()->find($parentId);

        return $parent !== null && $this->user()->can('createSubproject', $parent);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    /**
     * Redmine's API names the modules enabled_module_names; `modules` is this API's own name.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('enabled_module_names') && ! $this->has('modules')) {
            $this->merge(['modules' => $this->input('enabled_module_names')]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('projects', 'identifier')],
            'description' => ['nullable', 'string'],
            'homepage' => ['nullable', 'string', 'max:255'],
            'is_public' => ['boolean'],
            'inherit_members' => ['boolean'],
            'parent_id' => ['nullable', 'exists:projects,id'],
            // Optional: Redmine falls back to default_projects_tracker_ids, then every tracker.
            'tracker_ids' => ['sometimes', 'array', 'min:1'],
            'tracker_ids.*' => ['exists:trackers,id'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => [Rule::in(array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::cases()))],
            // A15-18: which issue custom fields apply to this project
            // (Redmine's project.issue_custom_field_ids=), same as the
            // project settings form. A field that already applies to
            // every project is unaffected either way — see
            // Project::syncIssueCustomFieldIds().
            'issue_custom_field_ids' => ['sometimes', 'array'],
            'issue_custom_field_ids.*' => ['integer', 'exists:custom_fields,id'],
        ];
    }
}
