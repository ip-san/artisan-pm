<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTimeEntryRequest extends FormRequest
{
    /**
     * The project the entry is logged in: the route's project, or — on
     * /issues/{issue}/time_entries — the issue's own project.
     */
    private function targetProject(): Project
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue ? $issue->project : $this->route('project');
    }

    public function authorize(): bool
    {
        $issue = $this->route('issue');

        // Logging time against an issue the caller cannot see would confirm
        // the issue exists, so it needs view access to the issue as well.
        if ($issue instanceof Issue && ! $this->user()->can('view', $issue)) {
            return false;
        }

        return $this->user()->can('create', [TimeEntry::class, $this->targetProject()]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['spent_on' => $this->input('spent_on', now()->toDateString())]);

        // On /issues/{issue}/time_entries the issue is the route's, whatever
        // the body claims.
        if (($issue = $this->route('issue')) instanceof Issue) {
            $this->merge(['issue_id' => $issue->id]);
        }

        // Defaults to the requester when omitted (mirrors the web form's
        // mount-time default), same as Redmine's TimeEntry.new(user:
        // User.current, ...). A user without log_time_for_other_users can only
        // ever log time for themselves — matches the web form's
        // canManageOthers restriction.
        $this->merge(['user_id' => $this->input('user_id', $this->user()->id)]);

        $project = $this->targetProject();

        if (! app(AuthorizationService::class)->can($this->user(), 'log_time_for_other_users', $project)) {
            $this->merge(['user_id' => $this->user()->id]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->targetProject();

        return [
            'issue_id' => ['nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $project->id)],
            // Not scoped to project membership (unlike the web form's
            // canManageOthers dropdown, which only ever offers actual
            // members): the requester's own default value must always
            // validate even when they hold log_time only through a
            // non-member role or the admin bypass, neither of which
            // implies an actual members row for this project.
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'activity_id' => ['required', 'integer', Rule::in($project->activities()->pluck('id'))],
            'hours' => ['required', 'numeric', 'min:0', 'max:1000'],
            'spent_on' => ['required', 'date'],
            // Redmine本家はcommentsの長さ上限を1024文字とするため、上限のない
            // 既存Web UIのフォーム(resources/views/livewire/time-entries/form.blade.php)
            // より厳しいが、本家のバリデーションに合わせてAPIではここで適用する。
            'comments' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
