<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MailNotificationOption;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use App\Rules\UniqueUserValueIgnoringCase;
use App\Support\Locale\SupportedLocales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * No policy check beyond being authenticated (auth:api,api-key already
 * guarantees that) — matches Redmine's own /my/account, gated only by
 * require_login, since this always acts on the requester's own record.
 * Fields mirror resources/views/livewire/profile/index.blade.php's
 * updateProfile() (name/email/mail_notification/no_self_notified/language),
 * plus `pref` (validated loosely — App\Support\Preferences\UserPreferences::save()
 * itself ignores anything it doesn't recognize, same as Redmine's
 * UserPreference#safe_attributes) and `custom_fields`
 * (App\Support\Api\CustomFieldPayload, not yet offered by the profile page
 * itself — A15-13).
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
            ...User::nameRules(false),
            'email' => ['sometimes', 'string', 'email', 'max:255', new UniqueUserValueIgnoringCase('email', $this->user()->id), new AllowedEmailDomain($this->user()->email)],
            'mail_notification' => ['sometimes', Rule::enum(MailNotificationOption::class)],
            'no_self_notified' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'nullable', Rule::in(array_keys(SupportedLocales::all()))],
            'pref' => ['sometimes', 'array'],
        ];
    }
}
