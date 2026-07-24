<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Member;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()->can('create', [Member::class, $project]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'user_id' => [
                'required_without:group_id',
                'prohibits:group_id',
                'integer',
                'exists:users,id',
                Rule::unique('members', 'user_id')->where('project_id', $project->id),
            ],
            'group_id' => [
                'required_without:user_id',
                'integer',
                'exists:groups,id',
                Rule::unique('members', 'group_id')->where('project_id', $project->id),
            ],
            // Role ids outside the requester's managed set are silently
            // dropped by the controller (matching Redmine's
            // Member#set_editable_role_ids), not rejected here — an
            // attacker-supplied id for a role the requester doesn't manage
            // simply has no effect rather than failing the whole request.
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
