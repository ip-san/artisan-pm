<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\EnumerationType;
use App\Models\Issue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('issue'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');
        $projectId = $issue->project_id;

        return [
            'tracker_id' => ['sometimes', Rule::exists('project_tracker', 'tracker_id')->where('project_id', $projectId)],
            'status_id' => ['sometimes', 'exists:issue_statuses,id'],
            'priority_id' => ['sometimes', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $projectId)],
            'fixed_version_id' => ['nullable', Rule::exists('versions', 'id')->where('project_id', $projectId)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'done_ratio' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
