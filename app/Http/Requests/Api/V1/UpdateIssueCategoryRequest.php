<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\IssueCategory;
use App\Support\Issues\AssigneeChoice;
use Closure;
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
            'assigned_to_group_id' => ['nullable', 'integer', 'prohibits:assigned_to_id', function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                if (! AssigneeChoice::allowsGroup($category->project, (int) $value, $category->assigned_to_group_id)) {
                    $fail(__('選択した担当者は無効です。'));
                }
            }],
        ];
    }
}
