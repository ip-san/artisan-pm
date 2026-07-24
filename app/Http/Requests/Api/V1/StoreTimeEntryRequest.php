<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [TimeEntry::class, $this->route('project')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['spent_on' => $this->input('spent_on', now()->toDateString())]);

        // Defaults to the requester when omitted (mirrors the web form's
        // mount-time default), same as Redmine's TimeEntry.new(user:
        // User.current, ...). A user without edit_time_entries can only
        // ever log time for themselves — matches the web form's
        // canManageOthers restriction, there is no Redmine-style
        // log_time_for_other_users permission in this app's registry,
        // edit_time_entries is reused for both.
        $this->merge(['user_id' => $this->input('user_id', $this->user()->id)]);

        /** @var Project $project */
        $project = $this->route('project');

        if (! app(AuthorizationService::class)->can($this->user(), 'edit_time_entries', $project)) {
            $this->merge(['user_id' => $this->user()->id]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

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
            'hours' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'spent_on' => ['required', 'date'],
            // Redmine本家はcommentsの長さ上限を1024文字とするため、上限のない
            // 既存Web UIのフォーム(resources/views/livewire/time-entries/form.blade.php)
            // より厳しいが、本家のバリデーションに合わせてAPIではここで適用する。
            'comments' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
