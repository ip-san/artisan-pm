<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\TimeEntry;
use App\Support\Authorization\AuthorizationService;
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
        /** @var TimeEntry $timeEntry */
        $timeEntry = $this->route('time_entry');

        if (! app(AuthorizationService::class)->can($this->user(), 'edit_time_entries', $timeEntry->project)) {
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
            'issue_id' => ['sometimes', 'nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $project->id)],
            // Not scoped to project membership — see StoreTimeEntryRequest
            // for why (log_time/edit_time_entries can be held without an
            // actual members row, via a non-member role or admin bypass).
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'activity_id' => ['sometimes', 'integer', Rule::in($project->activities()->pluck('id'))],
            'hours' => ['sometimes', 'numeric', 'min:0.01', 'max:1000'],
            'spent_on' => ['sometimes', 'date'],
            'comments' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
