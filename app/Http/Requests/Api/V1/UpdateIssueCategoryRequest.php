<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\IssueCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateIssueCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('issue_category'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var IssueCategory $category */
        $category = $this->route('issue_category');

        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('issue_categories', 'name')->where('project_id', $category->project_id)->ignore($category),
            ],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $category->project_id)],
        ];
    }
}
