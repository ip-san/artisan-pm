<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('journal'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Nullable/blank is intentional — Redmine's own journal edit
            // form allows saving empty notes, which is how a comment is
            // "removed" (see App\Policies\JournalPolicy's doc comment).
            // "sometimes" so an omitted key leaves notes untouched rather
            // than clearing it, matching the other Update*Request forms.
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
