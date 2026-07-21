<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pseudo-roles applied to public projects when a user has no explicit
 * Member row: an anonymous visitor, or a logged-in non-member. Ordinary
 * roles (`builtin = null`) are created by admins and assigned to members.
 */
enum RoleBuiltin: string
{
    case Anonymous = 'anonymous';
    case NonMember = 'non_member';
}
