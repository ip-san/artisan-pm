<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Group::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('groups', 'name')],
            // A full replace on create/update, matching Redmine's own
            // Group#safe_attributes= (user_ids=) — not the additive
            // POST /groups/:id/users nested endpoint, which this app
            // doesn't expose (no other resource here has a nested
            // membership sub-endpoint to follow that convention from).
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
