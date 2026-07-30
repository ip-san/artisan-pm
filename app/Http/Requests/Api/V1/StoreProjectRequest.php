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
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('projects', 'identifier')],
            'description' => ['nullable', 'string'],
            'is_public' => ['boolean'],
            'parent_id' => ['nullable', 'exists:projects,id'],
            'tracker_ids' => ['required', 'array', 'min:1'],
            'tracker_ids.*' => ['exists:trackers,id'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => [Rule::in(array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::cases()))],
        ];
    }
}
