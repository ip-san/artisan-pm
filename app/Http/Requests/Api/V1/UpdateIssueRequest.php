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

final class UpdateIssueRequest extends FormRequest
{
    private ?Project $namedProject = null;

    private bool $namedProjectResolved = false;

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
     * The project the body's project_id names (an id or an identifier), for
     * a caller who may edit the issue — Redmine's project_id is a safe
     * attribute of attributes_editable? only; for anyone else it is
     * ignored.
     */
    private function namedProject(): ?Project
    {
        if (! $this->namedProjectResolved) {
            $this->namedProjectResolved = true;
            $given = $this->input('project_id');

            if ($this->has('project_id') && $this->user()->can('update', $this->route('issue'))) {
                if (is_int($given) || (is_string($given) && ctype_digit($given))) {
                    $this->namedProject = Project::query()->find((int) $given);
                } elseif (is_string($given) && $given !== '') {
                    $this->namedProject = Project::query()->where('identifier', $given)->first();
                }
            }
        }

        return $this->namedProject;
    }

    /**
     * The project PUT /issues/{id} moves the issue to: one of Redmine's
     * allowed_target_projects other than its own (add_issues there, see
     * IssuePolicy::moveTo()). Null when the issue stays where it is.
     */
    public function movingTo(): ?Project
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');
        $project = $this->namedProject();

        return $project !== null && $project->id !== $issue->project_id && $this->user()->can('moveTo', [$issue, $project])
            ? $project
            : null;
    }

    /**
     * Redmine's safe_attributes= on a project change: a category the client
     * sends back unchanged belongs to the old project and is discarded (the
     * target's category of the same name takes over), and an unchanged
     * assignee is not re-validated (Issue#validate only checks a changed
     * assigned_to).
     */
    protected function prepareForValidation(): void
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');
        $project = $this->namedProject();

        if ($project === null || $project->id === $issue->project_id) {
            return;
        }

        $unchanged = collect(['category_id', 'assigned_to_id', 'assigned_to_group_id'])
            ->filter(fn (string $key) => $this->has($key) && $this->input($key) !== null && (string) $this->input($key) === (string) $issue->{$key})
            ->all();

        if ($unchanged !== []) {
            $this->replace($this->except($unchanged));
        }
    }

    /**
     * Every rule is scoped to the project the issue ends up in: the target
     * of a move (Redmine assigns the project first, then the rest), else
     * its own.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue')->loadMissing('project');
        $namedProject = $this->namedProject();
        $movingTo = $this->movingTo();
        $project = $movingTo ?? $issue->project;
        $projectId = $project->id;

        return [
            // Redmine's allowed_target_projects: the issue's own project or
            // one the caller may add issues to; any other (including one they
            // cannot see) is rejected alike.
            'project_id' => [function (string $attribute, mixed $value, Closure $fail) use ($issue, $namedProject, $movingTo): void {
                if ($this->user()->can('update', $issue) && $movingTo === null && $namedProject?->id !== $issue->project_id) {
                    $fail(__('選択したプロジェクトは無効です。'));
                }
            }],
            // Redmine's allowed_target_trackers: a tracker the caller's
            // add_issues roles allow in the resulting project, or the issue's
            // current one when that project uses it.
            'tracker_id' => ['sometimes', Rule::in(Issue::allowedTargetTrackers($project, $this->user(), (int) $issue->tracker_id)->pluck('id')->all())],
            'status_id' => ['sometimes', 'exists:issue_statuses,id'],
            'priority_id' => ['sometimes', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $projectId)],
            'assigned_to_group_id' => ['nullable', 'integer', 'prohibits:assigned_to_id', function (string $attribute, mixed $value, Closure $fail) use ($issue, $project): void {
                if (! AssigneeChoice::allowsGroup($project, (int) $value, $issue->assigned_to_group_id)) {
                    $fail(__('選択した担当者は無効です。'));
                }
            }],
            'category_id' => ['nullable', Rule::exists('issue_categories', 'id')->where('project_id', $projectId)],
            // Redmine's assignable_versions: an open version shared with the
            // project, or the issue's current one.
            'fixed_version_id' => ['nullable', Rule::in($project->sharedVersions()
                ->filter(fn (Version $version) => $version->status === VersionStatus::Open || $version->id === $issue->fixed_version_id)
                ->pluck('id')->all())],
            // Taken only from callers holding manage_subtasks.
            'parent_issue_id' => ['nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $projectId), new IssueParentTarget($project, $this->user(), $issue)],
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
