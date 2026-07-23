<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\IssueCategory;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreIssueCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [IssueCategory::class, $this->route('project')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('issue_categories', 'name')->where('project_id', $project->id),
            ],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $project->id)],
        ];
    }
}
