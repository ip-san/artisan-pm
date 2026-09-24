<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\EnumerationType;
use App\Enums\VersionStatus;
use App\Models\Issue;
use App\Models\Version;
use App\Rules\IssueParentTarget;
use App\Support\Issues\AssigneeChoice;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateIssueRequest extends FormRequest
{
    /**
     * Redmine's issues#update is open to editors and to those who may only
     * add notes (add_issue_notes); the controller keeps a notes-only
     * caller's other fields out, as Redmine's safe_attributes do.
     */
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $this->user()->can('update', $issue) || $this->user()->can('addNotes', $issue);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue')->loadMissing('project');
        $projectId = $issue->project_id;

        return [
            // Redmine's allowed_target_trackers: a tracker the caller's
            // add_issues roles allow, or the issue's current one.
            'tracker_id' => ['sometimes', Rule::in(Issue::allowedTargetTrackers($issue->loadMissing('project')->project, $this->user(), (int) $issue->tracker_id)->pluck('id')->all())],
            'status_id' => ['sometimes', 'exists:issue_statuses,id'],
            'priority_id' => ['sometimes', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $projectId)],
            'assigned_to_group_id' => ['nullable', 'integer', 'prohibits:assigned_to_id', function (string $attribute, mixed $value, Closure $fail) use ($issue): void {
                if (! AssigneeChoice::allowsGroup($issue->project, (int) $value, $issue->assigned_to_group_id)) {
                    $fail(__('選択した担当者は無効です。'));
                }
            }],
            'category_id' => ['nullable', Rule::exists('issue_categories', 'id')->where('project_id', $projectId)],
            // Redmine's assignable_versions: an open version shared with the
            // project, or the issue's current one.
            'fixed_version_id' => ['nullable', Rule::in($issue->project->sharedVersions()
                ->filter(fn (Version $version) => $version->status === VersionStatus::Open || $version->id === $issue->fixed_version_id)
                ->pluck('id')->all())],
            // Taken only from callers holding manage_subtasks.
            'parent_issue_id' => ['nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $projectId), new IssueParentTarget($issue->project, $this->user(), $issue)],
            // Taken only from callers who may set it on this issue.
            'is_private' => ['boolean'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'notes' => ['nullable', 'string'],
            // Honored only for callers holding set_notes_private.
            'private_notes' => ['boolean'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'done_ratio' => ['sometimes', 'integer', 'min:0', 'max:100'],
            // Optimistic locking, as Redmine's safe attribute of the same
            // name: the lock_version the client last read.
            'lock_version' => ['sometimes', 'integer', 'min:0'],
            'uploads' => ['array'],
            'uploads.*.token' => ['required', 'string'],
            'uploads.*.filename' => ['nullable', 'string', 'max:255'],
            'uploads.*.description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
