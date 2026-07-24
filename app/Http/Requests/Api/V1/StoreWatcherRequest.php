<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Issue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWatcherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageWatchers', $this->route('issue'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Issue $issue */
        $issue = $this->route('issue');

        return [
            'user_id' => ['required', 'integer', Rule::exists('members', 'user_id')->where('project_id', $issue->project_id)],
        ];
    }
}
