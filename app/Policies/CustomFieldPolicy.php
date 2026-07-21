<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomField;
use App\Models\User;

/**
 * Custom field definitions are a site-wide administrative resource (like
 * Role), so only administrators may manage them — enforced entirely via
 * the Gate::before admin bypass in AppServiceProvider.
 */
final class CustomFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, CustomField $customField): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CustomField $customField): bool
    {
        return false;
    }

    public function delete(User $user, CustomField $customField): bool
    {
        return false;
    }
}
