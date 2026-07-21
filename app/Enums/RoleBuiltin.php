<?php

namespace App\Enums;

/**
 * Redmine models "anonymous visitor" and "logged-in non-member" as pseudo-roles
 * that apply to public projects without an explicit Member row. `Regular` roles
 * are the ones administrators create and assign to project members.
 */
enum RoleBuiltin: string
{
    case Anonymous = 'anonymous';
    case NonMember = 'non_member';
}
