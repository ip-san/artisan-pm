<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * No policy check beyond being authenticated (auth:api,api-key already
 * guarantees that) — matches Redmine's own /my/account, gated only by
 * require_login, since this always acts on the requester's own record.
 * Fields mirror resources/views/livewire/profile/index.blade.php's
 * updateProfile() exactly (name/email only — no password/language/
 * notification prefs/custom fields in this first pass).
 */
final class UpdateMyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
        ];
    }
}
