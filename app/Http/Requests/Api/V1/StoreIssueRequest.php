<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\EnumerationType;
use App\Enums\VersionStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Version;
use App\Rules\IssueParentTarget;
use App\Support\Issues\AssigneeChoice;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreIssueRequest extends FormRequest
{
    private ?Project $targetProject = null;

    /**
     * The project the issue is created in: the one in the URL, or — for
     * Redmine's POST /issues.json — the body's project_id (an id or an
     * identifier). A body project that is missing or the caller may not
     * add issues to fails authorization alike, so its existence isn't
     * disclosed.
     */
    public function targetProject(): ?Project
    {
        if ($this->route('project') instanceof Project) {
            return $this->route('project');
        }

        if ($this->targetProject === null) {
            $given = $this->input('project_id');

            if (is_int($given) || (is_string($given) && ctype_digit($given))) {
                $this->targetProject = Project::query()->find((int) $given);
            } elseif (is_string($given) && $given !== '') {
                $this->targetProject = Project::query()->where('identifier', $given)->first();
            }
        }

        return $this->targetProject;
    }

    public function authorize(): bool
    {
        // Without any project_id the request is a validation error instead.
        if (! $this->route('project') instanceof Project && ! $this->filled('project_id')) {
            return true;
        }

        $project = $this->targetProject();

        return $project !== null && $this->user()->can('create', [Issue::class, $project]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->targetProject();

        if ($project === null) {
            return ['project_id' => ['required']];
        }

        return [
            // Scoped to this project so a crafted request can't attach an
            // issue to a tracker/version/assignee outside it — mirrors the
            // same rules in issues/form.blade.php's save() method.
            // Redmine's allowed_target_trackers: a project tracker the
            // caller's add_issues roles allow.
            'tracker_id' => ['required', Rule::in(Issue::allowedTargetTrackers($project, $this->user())->pluck('id')->all())],
            // One the workflow doesn't let the caller start in falls back to
            // the tracker's default, as in Redmine (IssueController::store()).
            'status_id' => ['nullable', 'integer'],
            'priority_id' => ['required', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'category_id' => ['nullable', Rule::exists('issue_categories', 'id')->where('project_id', $project->id)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $project->id)],
            // Users and groups have separate ids here, so a group assignee
            // (Redmine's assigned_to_id with a group id) has its own key.
            'assigned_to_group_id' => ['nullable', 'integer', 'prohibits:assigned_to_id', function (string $attribute, mixed $value, Closure $fail) use ($project): void {
                if (! AssigneeChoice::allowsGroup($project, (int) $value)) {
                    $fail(__('選択した担当者は無効です。'));
                }
            }],
            // Redmine's assignable_versions: an open version shared with
            // the project, as the issue form offers.
            'fixed_version_id' => ['nullable', Rule::in($project->sharedVersions()
                ->filter(fn (Version $version) => $version->status === VersionStatus::Open)
                ->pluck('id')->all())],
            // Taken only from callers holding manage_subtasks.
            'parent_issue_id' => ['nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $project->id), new IssueParentTarget($project, $this->user())],
            // Taken only from callers who may set it (set_issues_private or
            // set_own_issues_private).
            'is_private' => ['boolean'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            // Taken only from callers holding add_issue_watchers; members of
            // the project, as POST /issues/{id}/watchers requires.
            'watcher_user_ids' => ['array'],
            'watcher_user_ids.*' => ['integer', Rule::exists('members', 'user_id')->where('project_id', $project->id)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'done_ratio' => ['integer', 'min:0', 'max:100'],
            // A token that doesn't resolve (unknown/already-claimed) is
            // silently skipped by the controller rather than rejected
            // here — matches Redmine's own tolerant save_attachments,
            // which only warns rather than failing the whole request.
            'uploads' => ['array'],
            'uploads.*.token' => ['required', 'string'],
            'uploads.*.filename' => ['nullable', 'string', 'max:255'],
            'uploads.*.description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
