<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Issue;
use App\Models\TimeEntry;
use App\Support\Authorization\AuthorizationService;
use App\Support\Format\Hours;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('time_entry'));
    }

    protected function prepareForValidation(): void
    {
        // Redmine's TimeEntry#hours=: "1:30", "1h30", "1.5h" and the like.
        if (is_string($this->input('hours'))) {
            $this->merge(['hours' => Hours::normalizeInput($this->input('hours'))]);
        }

        /** @var TimeEntry $timeEntry */
        $timeEntry = $this->route('time_entry');

        if (! app(AuthorizationService::class)->can($this->user(), 'log_time_for_other_users', $timeEntry->project)) {
            $this->merge(['user_id' => $timeEntry->user_id]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var TimeEntry $timeEntry */
        $timeEntry = $this->route('time_entry');
        $project = $timeEntry->project;

        return [
            // Redmine's TimeEntry#safe_attributes=: only an issue the caller
            // may see, unless it is the entry's unchanged current issue.
            'issue_id' => ['sometimes', 'nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $project->id), function (string $attribute, mixed $value, \Closure $fail) use ($project, $timeEntry): void {
                if ((int) $value !== $timeEntry->issue_id && ! Issue::query()->whereKey((int) $value)->visibleTo($this->user(), $project)->exists()) {
                    $fail(__('課題が見つかりません。'));
                }
            }],
            // Not scoped to project membership — see StoreTimeEntryRequest
            // for why (log_time/log_time_for_other_users can be held without an
            // actual members row, via a non-member role or admin bypass).
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'activity_id' => ['sometimes', 'integer', Rule::in($project->activities()->pluck('id'))],
            'hours' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'spent_on' => ['sometimes', 'date'],
            'comments' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
