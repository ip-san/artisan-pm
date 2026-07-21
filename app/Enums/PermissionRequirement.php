<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Constrains which kind of role a permission may be granted to. This is
 * enforced when an administrator edits a role's permissions, not at
 * runtime — by the time AuthorizationService checks a permission, it only
 * matters whether the resolved role(s) were actually granted it.
 */
enum PermissionRequirement: string
{
    /** Grantable to any role, including the builtin Anonymous role. */
    case None = 'none';

    /** Grantable to any role for a logged-in user (Anonymous excluded). */
    case LoggedIn = 'logged_in';

    /** Grantable only to roles held via an explicit project Member row. */
    case Member = 'member';
}
