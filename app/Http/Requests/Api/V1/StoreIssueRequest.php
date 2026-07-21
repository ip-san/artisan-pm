<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\EnumerationType;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Issue::class, $this->route('project')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            // Scoped to this project so a crafted request can't attach an
            // issue to a tracker/version/assignee outside it — mirrors the
            // same rules in issues/form.blade.php's save() method.
            'tracker_id' => ['required', Rule::exists('project_tracker', 'tracker_id')->where('project_id', $project->id)],
            'priority_id' => ['required', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $project->id)],
            'fixed_version_id' => ['nullable', Rule::exists('versions', 'id')->where('project_id', $project->id)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'done_ratio' => ['integer', 'min:0', 'max:100'],
        ];
    }
}
